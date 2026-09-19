<?php

namespace App\Services;

use App\Contracts\SmsSender;
use App\Enums\AlertType;
use App\Mail\AlertPagedMail;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\ReferentDelegation;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;

/**
 * Lot 2 §2 — paging et escalade des alertes (`alert_notifications`).
 *
 * page()     : à la création d'une alerte high / critical ou de type vital.
 * escalate() : exécuté chaque minute (`carenest:escalate-alerts`). Le temps écoulé
 *              se compte en HEURES OUVRÉES de l'école pour les alertes non vitales
 *              (BusinessTime, lundi-vendredi), en continu 24h/24 pour les vitales.
 * ack()      : accusé de réception du référent (réutilisé par AlertTreatment).
 *
 * Aucun canal ne vise jamais l'enfant. E-mail et SMS ne portent aucune donnée
 * nominative ; seules les notifications internes à l'administration portent le
 * nom de l'élève et le type, chaque envoi étant audité.
 *
 * Spec §5.4 — l'administration n'est prévenue QUE sur un signal vital (danger,
 * pensées négatives) : c'est la seule dérogation à son anonymisation par défaut.
 * Spec §2.5b — le référent traite sous 60 minutes ; au-delà, l'administration agit.
 * Une alerte NON vitale s'arrête donc à la relance du référent (étape 1).
 */
class AlertPager
{
    public const STEP_MINUTES = [1 => 5, 2 => 60, 3 => 60];

    public const SMS_TEXT = 'CareNest : une alerte attend votre accusé de réception. Connectez-vous à votre espace référent.';

    public function __construct(
        private readonly Notifier $notifier,
        private readonly SmsSender $sms,
    ) {}

    // ── Paging initial ────────────────────────────────────────────────────

    public function page(Alert $alert): void
    {
        $type  = AlertType::tryFrom((string) $alert->type);
        $vital = $type?->isVital() ?? false;

        if (! $vital && ! in_array($alert->level, ['high', 'critical'], true)) {
            return;
        }

        // Idempotence : un seul paging initial (step 0, canal app) par alerte.
        if (AlertNotification::where('alert_id', $alert->id)->where('escalation_step', 0)->where('channel', 'app')->exists()) {
            return;
        }

        $school   = $alert->school;
        $referent = $school?->referent();
        $delegate = $school ? $this->activeDelegate($school) : null;
        $link     = '/dashboard-referent/alertes/' . $alert->id;

        if ($referent) {
            $this->notifyApp($alert, $referent, 0, 'alerte', 'Une alerte attend votre accusé', 'Un signal vient d\'être détecté. Prenez-en connaissance dans votre espace référent.', $link);
            $this->sendEmail($alert, $referent, 0);
        }

        if ($vital) {
            if ($referent) {
                $this->sendSms($alert, $referent, 0, $school?->setting?->referent_phone ?: $referent->phone);
            }
            if ($delegate) {
                $this->notifyApp($alert, $delegate, 0, 'alerte', 'Une alerte attend un accusé (délégation)', 'Un signal vital vient d\'être détecté. Le référent titulaire est également prévenu.', $link);
                $this->sendSms($alert, $delegate, 0, $delegate->phone);
            }
            if ($school) {
                $this->notifyAdmins($alert, $school, 0, 'admin.vital.notify');
            }
        }
    }

    // ── Escalade ──────────────────────────────────────────────────────────

    public function escalate(?Carbon $now = null): void
    {
        $now = $now ?? now();

        $pending = Alert::query()
            ->where('status', '!=', 'resolved')
            ->whereNull('escalation_exhausted_at')
            ->whereHas('notifications', fn ($q) => $q->where('escalation_step', 0)->where('channel', 'app')->whereNotNull('recipient_id')->whereNull('acked_at'))
            ->whereDoesntHave('notifications', fn ($q) => $q->whereNotNull('acked_at'))
            ->with('school.setting')
            ->get();

        foreach ($pending as $alert) {
            try {
                $this->escalateOne($alert, $now);
            } catch (\Throwable $e) {
                Log::error('Escalade en échec', ['alert' => $alert->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function escalateOne(Alert $alert, Carbon $now): void
    {
        $elapsed = $this->elapsedMinutes($alert, $now);
        $school  = $alert->school;
        $link    = '/dashboard-referent/alertes/' . $alert->id;

        // Étape 1 — relance référent (app + e-mail + SMS) + délégué.
        if ($elapsed >= self::STEP_MINUTES[1] && ! $this->stepDone($alert, 1)) {
            $referent = $school?->referent();
            if ($referent) {
                $this->notifyApp($alert, $referent, 1, 'alerte', 'Rappel : une alerte attend votre accusé', 'Cette alerte n\'a pas encore été prise en connaissance.', $link);
                $this->sendEmail($alert, $referent, 1);
                $this->sendSms($alert, $referent, 1, $school?->setting?->referent_phone ?: $referent->phone);
            }
            $delegate = $school ? $this->activeDelegate($school) : null;
            if ($delegate) {
                $this->notifyApp($alert, $delegate, 1, 'alerte', 'Une alerte attend un accusé (délégation)', 'Le référent titulaire n\'a pas encore accusé réception.', $link);
            }
            if (! $referent && ! $delegate) {
                $this->journal($alert, 1, 'app', null);
            }
        }

        // Étape 2 — administration (nom + type, audité), RÉSERVÉE aux signaux vitaux
        // (spec §5.4), après 60 minutes sans accusé du référent (spec §2.5b).
        if ($elapsed >= self::STEP_MINUTES[2] && ! $this->stepDone($alert, 2)) {
            $vital = AlertType::tryFrom((string) $alert->type)?->isVital() ?? false;
            if ($vital && $school) {
                $this->notifyAdmins($alert, $school, 2, 'admin.alert.escalation');
            }
            if (! $this->stepDone($alert, 2)) {
                $this->journal($alert, 2, 'app', null);
            }
        }

        // Étape 3 — escalade épuisée (visible dans « Urgences sans accusé »).
        if ($elapsed >= self::STEP_MINUTES[3] && $alert->escalation_exhausted_at === null) {
            $alert->update(['escalation_exhausted_at' => $now]);
            $this->journal($alert, 3, 'app', null);
        }
    }

    /** Minutes écoulées depuis la création : ouvrées (non vital) ou réelles (vital). */
    public function elapsedMinutes(Alert $alert, Carbon $now): int
    {
        $type = AlertType::tryFrom((string) $alert->type);
        $from = Carbon::parse($alert->created_at);

        if ($type?->isVital()) {
            return (int) max(0, $from->diffInMinutes($now, false));
        }

        $setting = $alert->school?->setting;

        return BusinessTime::minutesBetween(
            $from,
            $now,
            (string) ($setting?->school_hours_start ?? '08:00'),
            (string) ($setting?->school_hours_end ?? '17:00'),
        );
    }

    // ── Accusé de réception ───────────────────────────────────────────────

    public function ack(Alert $alert, User $user): void
    {
        DB::transaction(function () use ($alert, $user) {
            $notification = AlertNotification::firstOrCreate(
                ['alert_id' => $alert->id, 'recipient_id' => $user->id, 'channel' => 'app'],
                ['sent_at' => now(), 'escalation_step' => 0]
            );
            if ($notification->acked_at === null) {
                $notification->update(['acked_at' => now()]);
            }
            AlertNotification::where('alert_id', $alert->id)
                ->where('recipient_id', $user->id)
                ->whereNull('acked_at')
                ->update(['acked_at' => now()]);

            Alert::whereKey($alert->id)->where('status', 'unread')->update(['status' => 'read']);
            $alert->refresh();
        });
    }

    // ── Canaux ────────────────────────────────────────────────────────────

    private function notifyApp(Alert $alert, User $user, int $step, string $type, string $title, string $body, ?string $link): void
    {
        $this->notifier->notify($user, $type, $title, $body, $link);
        $this->journal($alert, $step, 'app', $user->id);
    }

    private function notifyAdmins(Alert $alert, School $school, int $step, string $auditAction): void
    {
        $admins = $school->users()->where('users.role', 'admin')->whereNull('users.deactivated_at')->get();
        if ($admins->isEmpty()) {
            return;
        }

        $name  = $alert->child?->name ?? 'Élève';
        $label = AlertType::labelFor($alert->type);
        $title = $step === 0 ? 'Signal vital détecté' : 'Alerte sans accusé du référent';
        $body  = $step === 0
            ? "{$name} : signal de type « {$label} ». Le référent est prévenu en parallèle."
            : "{$name} : signal de type « {$label} » sans accusé de réception du référent depuis " . self::STEP_MINUTES[2] . ' minutes.';

        foreach ($admins as $admin) {
            $this->notifyApp($alert, $admin, $step, 'alerte_admin', $title, $body, '/dashboard');
        }
        Audit::log($auditAction, $alert, ['school_id' => $school->id]);
    }

    private function sendEmail(Alert $alert, User $user, int $step): void
    {
        if (! $user->email) {
            return;
        }
        try {
            $mailable = new AlertPagedMail($alert->id, $step);
            $pending  = Mail::to($user->email);
            $this->deliver($pending, $mailable);
        } catch (\Throwable $e) {
            Log::warning('E-mail de paging non envoyé', ['alert' => $alert->id, 'error' => $e->getMessage()]);
        }
        $this->journal($alert, $step, 'email', $user->id);
    }

    /**
     * Lot 3 — un e-mail d'alerte ne doit jamais dépendre d'un worker de file :
     * avec `QUEUE_CONNECTION=database` et aucun `queue:work`, `queue()` laissait
     * l'e-mail dormir dans la table `jobs` (constaté en recette). File `sync` →
     * envoi immédiat ; sinon envoi juste après la réponse HTTP (ou en fin de
     * commande), sans latence pour l'enfant et sans dépendre d'un worker.
     */
    private function deliver(PendingMail $pending, Mailable $mailable): void
    {
        if (config('queue.default') === 'sync') {
            $pending->send($mailable);
            return;
        }

        app()->terminating(function () use ($pending, $mailable) {
            try {
                $pending->send($mailable);
            } catch (\Throwable $e) {
                Log::warning("E-mail d'alerte non envoyé (après réponse)", ['error' => $e->getMessage()]);
            }
        });
    }

    private function sendSms(Alert $alert, User $user, int $step, ?string $phone): void
    {
        if (! $phone) {
            return;
        }
        try {
            $this->sms->send($phone, self::SMS_TEXT);
        } catch (\Throwable $e) {
            Log::warning('SMS non envoyé', ['alert' => $alert->id, 'error' => $e->getMessage()]);
        }
        $this->journal($alert, $step, 'sms', $user->id);
    }

    private function journal(Alert $alert, int $step, string $channel, ?int $recipientId): AlertNotification
    {
        return AlertNotification::create([
            'alert_id'        => $alert->id,
            'channel'         => $channel,
            'recipient_id'    => $recipientId,
            'escalation_step' => $step,
            'sent_at'         => now(),
        ]);
    }

    private function stepDone(Alert $alert, int $step): bool
    {
        return AlertNotification::where('alert_id', $alert->id)->where('escalation_step', $step)->exists();
    }

    private function activeDelegate(School $school): ?User
    {
        $delegation = ReferentDelegation::query()->active()->where('school_id', $school->id)->latest('activated_at')->first();

        return $delegation?->delegate;
    }
}
