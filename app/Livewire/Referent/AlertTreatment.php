<?php

namespace App\Livewire\Referent;

use App\Livewire\Concerns\ResolvesReferentAccess;
use App\Models\Alert;
use App\Models\AlertAction;
use App\Models\AlertLifecycle;
use App\Models\AlertNotification;
use App\Models\FollowUp;
use App\Models\School;
use App\Models\User;
use App\Services\Audit;
use App\Services\ChildStatusResolver;
use App\Services\SynthesisSender;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Traitement d'une alerte en cinq étapes (lot 1 §3.4) :
 *  1 Signal · 2 Qualification (obligatoire) · 3 Action · 4 Suivi · 5 Information du parent.
 * Les étapes 3 à 5 et la clôture sont refusées côté serveur tant qu'aucune
 * qualification n'existe. Le délégué ne peut que accuser réception, qualifier et agir.
 */
#[Layout('layouts.app')]
class AlertTreatment extends Component
{
    use ResolvesReferentAccess;

    public Alert $alert;

    // Étape 3
    public array $actionTypes = [];
    public string $actionNotes = '';

    // Étape 4
    public string $followStatus = 'surveillance';
    public ?string $followDate = null;
    public string $followObjective = '';
    public ?int $followResponsable = null;

    // Étape 5
    public string $synthesisIdentified = '';
    public string $synthesisSchoolDid = '';
    public string $synthesisSchoolProposes = '';
    public string $synthesisParentCan = '';

    public ?string $flash = null;

    protected School $school;

    public function mount(Alert $alert): void
    {
        $this->school = $this->resolveReferentSchool($alert->school);
        $this->alert = $alert;
        $this->followResponsable = Auth::id();
        Audit::log('referent.alert.view', $alert);
    }

    public function hydrate(): void
    {
        $this->school = $this->resolveReferentSchool($this->alert->school);
    }

    // ── Garde-fous serveur ────────────────────────────────────────────────

    private function requireQualified(): bool
    {
        if (! $this->alert->isQualified()) {
            $this->addError('qualification', 'Qualifiez d\'abord le signal (étape 2) avant toute action.');
            return false;
        }
        return true;
    }

    private function transition(string $status, ?string $qualification = null): void
    {
        AlertLifecycle::create([
            'alert_id'      => $this->alert->id,
            'status'        => $status,
            'qualification' => $qualification,
            'changed_by'    => Auth::id(),
            'changed_at'    => now(),
        ]);
    }

    // ── Accusé de réception ───────────────────────────────────────────────

    public function acknowledge(): void
    {
        DB::transaction(function () {
            $notification = AlertNotification::firstOrCreate(
                ['alert_id' => $this->alert->id, 'recipient_id' => Auth::id(), 'channel' => 'app'],
                ['sent_at' => now(), 'escalation_step' => 0]
            );
            if ($notification->acked_at === null) {
                $notification->update(['acked_at' => now()]);
            }
            if ($this->alert->status === 'unread') {
                $this->alert->update(['status' => 'read']);
            }
        });
        Audit::log('referent.alert.ack', $this->alert);
        $this->alert->refresh();
        $this->flash = 'Prise de connaissance enregistrée.';
    }

    // ── Étape 2 : qualification ───────────────────────────────────────────

    public function qualify(string $qualification): void
    {
        abort_unless(in_array($qualification, AlertLifecycle::QUALIFICATIONS, true), 422);
        abort_if($this->alert->isClosed(), 422);

        DB::transaction(function () use ($qualification) {
            $this->transition('qualifie', $qualification);
            if ($this->alert->status === 'unread') {
                $this->alert->update(['status' => 'read']);
            }
        });
        Audit::log('referent.alert.qualify', $this->alert);
        $this->alert->refresh();
        $this->resetErrorBag('qualification');
        $this->flash = 'Signal qualifié : ' . AlertLifecycle::qualificationLabel($qualification) . '.';
    }

    // ── Étape 3 : actions ─────────────────────────────────────────────────

    public function saveAction(): void
    {
        if (! $this->requireQualified()) {
            return;
        }
        $this->validate([
            'actionTypes'   => ['required', 'array', 'min:1'],
            'actionTypes.*' => ['in:' . implode(',', AlertAction::TYPES)],
            'actionNotes'   => ['nullable', 'string', 'max:2000'],
        ], [], ['actionTypes' => 'actions', 'actionNotes' => 'notes']);

        DB::transaction(function () {
            foreach ($this->actionTypes as $type) {
                AlertAction::create([
                    'alert_id'     => $this->alert->id,
                    'action_type'  => $type,
                    'performed_by' => Auth::id(),
                    'performed_at' => now(),
                    'notes'        => trim($this->actionNotes) !== '' ? trim($this->actionNotes) : null,
                ]);
            }
            $this->transition('en_traitement');
        });
        Audit::log('referent.alert.action', $this->alert);
        $this->reset('actionTypes', 'actionNotes');
        $this->alert->refresh();
        $this->flash = 'Action enregistrée.';
    }

    // ── Étape 4 : suivi ───────────────────────────────────────────────────

    public function saveFollowUp(): void
    {
        $this->denyDelegate();
        if (! $this->requireQualified()) {
            return;
        }
        $this->validate([
            'followStatus'      => ['required', 'in:' . implode(',', FollowUp::STATUSES)],
            'followDate'        => ['nullable', 'date', 'after_or_equal:today'],
            'followObjective'   => ['nullable', 'string', 'max:1000'],
            'followResponsable' => ['nullable', 'integer', 'exists:users,id'],
        ], [], ['followStatus' => 'statut', 'followDate' => 'date du prochain point', 'followObjective' => 'objectif', 'followResponsable' => 'responsable']);

        DB::transaction(function () {
            FollowUp::create([
                'child_id'         => $this->alert->child_id,
                'alert_id'         => $this->alert->id,
                'status'           => $this->followStatus,
                'next_review_date' => $this->followDate ?: null,
                'objectif'         => trim($this->followObjective) !== '' ? trim($this->followObjective) : null,
                'responsable_id'   => $this->followResponsable,
            ]);
            $this->transition('suivi');
        });
        Audit::log('referent.alert.follow_up', $this->alert);
        $this->reset('followObjective', 'followDate');
        $this->alert->refresh();
        $this->flash = 'Suivi planifié.';
    }

    // ── Étape 5 : information du parent ───────────────────────────────────

    public function prefillSynthesis(SynthesisSender $sender): void
    {
        $this->denyDelegate();
        $d = $sender->defaults($this->alert->child, $this->alert);
        $this->synthesisIdentified     = $d['identified'];
        $this->synthesisSchoolDid      = $d['school_did'];
        $this->synthesisSchoolProposes = $d['school_proposes'];
        $this->synthesisParentCan      = $d['parent_can'];
    }

    public function sendSynthesis(SynthesisSender $sender): void
    {
        $this->denyDelegate();
        if (! $this->requireQualified()) {
            return;
        }
        if (! $this->alert->child->hasActiveConsent()) {
            $this->addError('synthesis', 'Aucun parent avec un consentement actif : l\'information ne peut pas être envoyée.');
            return;
        }
        if ($this->synthesisIdentified === '') {
            $this->prefillSynthesis($sender);
        }
        $this->validate([
            'synthesisIdentified'     => ['required', 'string', 'max:2000'],
            'synthesisSchoolDid'      => ['required', 'string', 'max:2000'],
            'synthesisSchoolProposes' => ['required', 'string', 'max:2000'],
            'synthesisParentCan'      => ['required', 'string', 'max:2000'],
        ]);

        $sender->send($this->alert->child, $this->alert, Auth::user(), [
            'identified'      => $this->synthesisIdentified,
            'school_did'      => $this->synthesisSchoolDid,
            'school_proposes' => $this->synthesisSchoolProposes,
            'parent_can'      => $this->synthesisParentCan,
        ]);
        $this->alert->refresh();
        $this->flash = 'Synthèse envoyée au parent.';
    }

    // ── Clôture ───────────────────────────────────────────────────────────

    public function closeAlert(): void
    {
        $this->denyDelegate();
        if (! $this->requireQualified()) {
            return;
        }
        DB::transaction(function () {
            $this->transition('cloture');
            $this->alert->update(['status' => 'resolved']);

            $child = $this->alert->child()->first();
            $child->status = app(ChildStatusResolver::class)->resolve($child->score_enfant, $child);
            $child->save();
        });
        Audit::log('referent.alert.close', $this->alert);
        $this->alert->refresh();
        $this->flash = 'Alerte clôturée.';
    }

    public function render()
    {
        $this->alert->load(['child', 'lifecycle.changer', 'actions.performer', 'followUps.responsable', 'syntheses.parent', 'reopenedFrom']);

        $responsables = User::query()
            ->whereIn('id', $this->school->users()->select('users.id'))
            ->where('role', '!=', 'parent')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.referent.alert-treatment', [
            'school'        => $this->school,
            'delegateMode'  => $this->delegateMode,
            'qualification' => $this->alert->currentQualification(),
            'stage'         => $this->alert->currentStage(),
            'qualified'     => $this->alert->isQualified(),
            'closed'        => $this->alert->isClosed(),
            'acked'         => $this->alert->notifications()->where('recipient_id', Auth::id())->whereNotNull('acked_at')->exists(),
            'hasConsent'    => $this->alert->child->hasActiveConsent(),
            'responsables'  => $responsables,
        ]);
    }
}
