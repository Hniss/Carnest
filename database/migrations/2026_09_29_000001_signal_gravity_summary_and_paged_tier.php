<?php

use App\Models\Alert;
use App\Models\ChatSession;
use App\Services\AlertPager;
use App\Services\SignalSeverity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'alerte suit la gravité réelle de la conversation, et ne part jamais sans résumé.
 *
 * Trois colonnes nouvelles :
 *  - `chat_sessions.worst_alert_type` : le type qui accompagnait le PIRE moment.
 *    Jusqu'ici le type ne vivait que dans le navigateur de l'enfant ; dès qu'il
 *    partait, la clôture d'inactivité écrivait « detresse » en dur.
 *  - `chat_sessions.worst_level` : le niveau le plus grave calculé pendant la
 *    session, tant que les messages sont encore en mémoire. Après le départ de
 *    l'enfant, plus aucun message brut n'existe (base fondatrice B5) : sans cette
 *    colonne, le niveau ne peut plus être qu'un mappage grossier.
 *  - `alerts.paged_tier` : palier de notification DÉJÀ servi (0 personne,
 *    1 référent, 2 chaîne vitale). Permet de relancer la chaîne sur un palier
 *    nouvellement atteint sans jamais renotifier un palier déjà servi.
 *
 * MIGRATION DES VALEURS EXISTANTES — la signification de trois données change,
 * les valeurs déjà en base sont donc converties ici, dans le même commit :
 *
 *  M1. `alerts.paged_tier` — une alerte qui a déjà reçu son paging initial est
 *      marquée au palier que sa nature mérite aujourd'hui. C'est exactement ce
 *      qui lui a été servi, puisque l'ancien code émettait le palier complet en
 *      un seul geste. Conséquence voulue : AUCUNE alerte déjà notifiée n'est
 *      renotifiée après la bascule.
 *
 *  M2. `alerts.summary` — les alertes créées en cours de session sont parties
 *      avec un résumé vide (le résumé n'existait qu'à la clôture) et n'étaient
 *      jamais complétées : le référent lisait « Aucun résumé disponible pour ce
 *      signal. ». On recopie le résumé de la session liée quand il existe. Quand
 *      il n'existe pas non plus, on écrit une mention explicite : le référent doit
 *      savoir qu'il regarde un défaut historique, pas un signal vide.
 *
 *  M3. `chat_sessions.worst_alert_type` / `worst_level` — repris de l'alerte liée
 *      quand elle existe, laissés vides sinon. On ne devine rien : les messages
 *      bruts des sessions passées n'existent pas et ne peuvent pas être rejoués.
 *
 * Ce qui n'est PAS fait, volontairement : aucune alerte rétroactive n'est créée
 * pour une session ancienne restée ouverte, et aucune notification n'est émise
 * par cette migration. Un signal vieux de plusieurs semaines ne se notifie pas.
 */
return new class extends Migration
{
    /** Mention écrite quand aucun résumé n'existe ni sur l'alerte ni sur sa session. */
    public const LEGACY_SUMMARY = 'Résumé indisponible — alerte antérieure à la correction du 29/09/2026 : le résumé de la conversation n\'a pas été enregistré au moment du signal.';

    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->string('worst_alert_type', 32)->nullable()->after('zone');
            $table->string('worst_level', 16)->nullable()->after('worst_alert_type');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->unsignedTinyInteger('paged_tier')->default(0)->after('level');
        });

        $this->backfillPagedTier();
        $this->backfillSessionWorstSignal();
        $this->backfillAlertSummaries();
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropColumn(['worst_alert_type', 'worst_level']);
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn('paged_tier');
        });
    }

    /** M1 — palier déjà servi, déduit des notifications réellement émises. */
    private function backfillPagedTier(): void
    {
        $alreadyPaged = DB::table('alert_notifications')
            ->where('escalation_step', 0)
            ->where('channel', 'app')
            ->distinct()
            ->pluck('alert_id');

        if ($alreadyPaged->isEmpty()) {
            return;
        }

        Alert::query()->whereIn('id', $alreadyPaged)->each(function (Alert $alert) {
            $tier = AlertPager::tierFor($alert);
            if ($tier > 0) {
                DB::table('alerts')->where('id', $alert->id)->update(['paged_tier' => $tier]);
            }
        });
    }

    /**
     * M3 — type et niveau du pire moment, repris de l'alerte liée. Rien n'est deviné.
     *
     * Une session est censée ne porter qu'une alerte, mais rien ne l'impose en base
     * (pas d'index unique sur `alerts.session_id`). On retient donc le PIRE des
     * alertes rencontrées, jamais la dernière lue : la colonne est monotone.
     */
    private function backfillSessionWorstSignal(): void
    {
        Alert::query()->whereNotNull('session_id')->orderBy('id')->each(function (Alert $alert) {
            $current = DB::table('chat_sessions')
                ->where('id', $alert->session_id)
                ->first(['worst_alert_type', 'worst_level']);

            if ($current === null) {
                return;
            }

            DB::table('chat_sessions')
                ->where('id', $alert->session_id)
                ->update([
                    'worst_alert_type' => SignalSeverity::maxType($current->worst_alert_type, $alert->type),
                    'worst_level'      => SignalSeverity::maxLevel($current->worst_level, $alert->level),
                ]);
        });
    }

    /** M2 — aucune alerte ne reste sans résumé : celui de la session, sinon une mention explicite. */
    private function backfillAlertSummaries(): void
    {
        Alert::query()->with('session')->orderBy('id')->each(function (Alert $alert) {
            if (trim((string) $alert->summary) !== '') {
                return;
            }

            $sessionSummary = trim((string) ($alert->session?->ai_summary ?? ''));

            $alert->summary = $sessionSummary !== '' ? $sessionSummary : self::LEGACY_SUMMARY;
            $alert->saveQuietly();
        });
    }
};
