<?php

namespace App\Livewire\Referent;

use App\Livewire\Concerns\ResolvesReferentAccess;
use App\Models\AdminNote;
use App\Models\Alert;
use App\Models\AlertAction;
use App\Models\AlertLifecycle;
use App\Models\Child;
use App\Models\FollowUp;
use App\Models\ParentSynthesis;
use App\Models\School;
use App\Services\Audit;
use App\Services\SynthesisSender;
use App\Services\WellbeingTrendResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fiche élève du référent (lot 1 §3.3) : profil, état actuel, frise, tendance,
 * notes internes (referent_id), information du parent. Chaque ouverture est auditée.
 */
#[Layout('layouts.app')]
class StudentProfile extends Component
{
    use ResolvesReferentAccess;

    public Child $child;

    public string $newNote = '';

    public bool $synthesisOpen = false;
    public string $synthesisIdentified = '';
    public string $synthesisSchoolDid = '';
    public string $synthesisSchoolProposes = '';
    public string $synthesisParentCan = '';

    public ?string $flash = null;

    protected School $school;

    public function mount(Child $child): void
    {
        $this->school = $this->resolveReferentSchool($child->school);
        $this->denyDelegate();
        $this->child = $child;
        Audit::log('referent.child.view', $child);
    }

    public function hydrate(): void
    {
        $this->school = $this->resolveReferentSchool($this->child->school);
        $this->denyDelegate();
    }

    public function addNote(): void
    {
        $this->validate(['newNote' => ['required', 'string', 'min:5', 'max:500']], [], ['newNote' => 'note']);

        AdminNote::create([
            'child_id'    => $this->child->id,
            'user_id'     => Auth::id(),
            'referent_id' => Auth::id(),
            'content'     => trim($this->newNote),
        ]);
        Audit::log('referent.child.note', $this->child);
        $this->reset('newNote');
        $this->flash = 'Note enregistrée.';
    }

    public function openSynthesis(SynthesisSender $sender): void
    {
        $d = $sender->defaults($this->child, $this->latestAlert());
        $this->synthesisIdentified     = $d['identified'];
        $this->synthesisSchoolDid      = $d['school_did'];
        $this->synthesisSchoolProposes = $d['school_proposes'];
        $this->synthesisParentCan      = $d['parent_can'];
        $this->synthesisOpen = true;
    }

    public function sendSynthesis(SynthesisSender $sender): void
    {
        if (! $this->child->hasActiveConsent()) {
            $this->addError('synthesis', 'Aucun parent avec un consentement actif : l\'information ne peut pas être envoyée.');
            return;
        }
        $this->validate([
            'synthesisIdentified'     => ['required', 'string', 'max:2000'],
            'synthesisSchoolDid'      => ['required', 'string', 'max:2000'],
            'synthesisSchoolProposes' => ['required', 'string', 'max:2000'],
            'synthesisParentCan'      => ['required', 'string', 'max:2000'],
        ]);

        $sender->send($this->child, $this->latestAlert(), Auth::user(), [
            'identified'      => $this->synthesisIdentified,
            'school_did'      => $this->synthesisSchoolDid,
            'school_proposes' => $this->synthesisSchoolProposes,
            'parent_can'      => $this->synthesisParentCan,
        ]);
        $this->synthesisOpen = false;
        $this->flash = 'Synthèse envoyée au parent.';
    }

    private function latestAlert(): ?Alert
    {
        return $this->child->alerts()->latest('created_at')->first();
    }

    public function render()
    {
        $child = $this->child->load(['parents', 'latestFollowUp.responsable']);

        $lastAlert = $this->latestAlert()?->load(['lifecycle', 'actions']);
        $lastAction = AlertAction::whereIn('alert_id', $child->alerts()->select('id'))->with('performer')->latest('performed_at')->first();
        $nextFollowUp = FollowUp::where('child_id', $child->id)->where('status', '!=', 'termine')
            ->whereNotNull('next_review_date')->whereDate('next_review_date', '>=', now()->toDateString())
            ->orderBy('next_review_date')->first();

        $alertIds = $child->alerts()->pluck('id');

        $timeline = collect()
            ->merge(Alert::whereIn('id', $alertIds)->get()->map(fn ($a) => [
                'at' => $a->created_at, 'kind' => 'signal', 'label' => 'Signal détecté',
                'detail' => 'Alerte n° ' . $a->id . ' · gravité ' . mb_strtolower(Alert::levelLabel($a->level)),
                'link' => route('referent.alerts.show', $a->id),
            ]))
            ->merge(AlertLifecycle::whereIn('alert_id', $alertIds)->with('changer')->get()->map(fn ($l) => [
                'at' => $l->changed_at, 'kind' => 'lifecycle',
                'label' => AlertLifecycle::statusLabel($l->status) . ($l->qualification ? ' · ' . AlertLifecycle::qualificationLabel($l->qualification) : ''),
                'detail' => $l->changer?->name ?? '', 'link' => route('referent.alerts.show', $l->alert_id),
            ]))
            ->merge(AlertAction::whereIn('alert_id', $alertIds)->with('performer')->get()->map(fn ($a) => [
                'at' => $a->performed_at, 'kind' => 'action', 'label' => AlertAction::typeLabel($a->action_type),
                'detail' => $a->performer?->name ?? '', 'link' => route('referent.alerts.show', $a->alert_id),
            ]))
            ->merge(FollowUp::where('child_id', $child->id)->with('responsable')->get()->map(fn ($f) => [
                'at' => $f->created_at, 'kind' => 'follow', 'label' => 'Suivi : ' . FollowUp::statusLabel($f->status),
                'detail' => ($f->next_review_date ? 'prochain point le ' . $f->next_review_date->format('d/m/Y') : '') . ($f->responsable ? ' · ' . $f->responsable->name : ''),
                'link' => $f->alert_id ? route('referent.alerts.show', $f->alert_id) : null,
            ]))
            ->merge(ParentSynthesis::where('child_id', $child->id)->with('parent')->get()->map(fn ($s) => [
                'at' => $s->sent_at, 'kind' => 'synthesis', 'label' => 'Synthèse envoyée au parent',
                'detail' => ($s->parent?->name ?? '') . ($s->read_at ? ' · lue' : ' · non lue'), 'link' => null,
            ]))
            ->sortByDesc('at')
            ->values();

        $report = app(WellbeingTrendResolver::class)->resolve($child);

        $notes = AdminNote::where('child_id', $child->id)->with('referent', 'user')->latest()->get();

        return view('livewire.referent.student-profile', [
            'school'       => $this->school,
            'lastAlert'    => $lastAlert,
            'lastAction'   => $lastAction,
            'nextFollowUp' => $nextFollowUp,
            'timeline'     => $timeline,
            'report'       => $report,
            'notes'        => $notes,
            'hasConsent'   => $child->hasActiveConsent(),
        ]);
    }
}
