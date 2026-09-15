<?php

namespace App\Livewire\Referent;

use App\Livewire\Concerns\ResolvesReferentAccess;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\FollowUp;
use App\Models\School;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Vue d'ensemble du référent (lot 1 §3.1) : compteurs par urgence, file des
 * alertes non qualifiées, files « à confirmer » et « à relire », répartition
 * par classe, suivis à échéance sous 7 jours.
 */
#[Layout('layouts.app')]
class Overview extends Component
{
    use ResolvesReferentAccess;

    #[Url(as: 'filtre')]
    public ?string $filter = null;

    protected School $school;

    public function mount(): void
    {
        $this->school = $this->resolveReferentSchool();
    }

    public function hydrate(): void
    {
        $this->school = $this->resolveReferentSchool();
    }

    public function setFilter(?string $filter): void
    {
        $this->filter = in_array($filter, ['urgent', 'en_cours', 'surveillance'], true) ? $filter : null;
    }

    /** Alertes actives (non résolues) de l'école, avec leur cycle de vie. */
    private function activeAlerts(): Collection
    {
        return Alert::query()
            ->with(['child:id,name,classe', 'lifecycle'])
            ->where('school_id', $this->school->id)
            ->where('status', '!=', 'resolved')
            ->orderBy('created_at')
            ->get();
    }

    private static function qualificationOf(Alert $alert): ?string
    {
        return $alert->lifecycle->whereNotNull('qualification')->sortByDesc('changed_at')->first()?->qualification;
    }

    private static function stageOf(Alert $alert): string
    {
        return $alert->lifecycle->sortByDesc('changed_at')->sortByDesc('id')->first()?->status ?? 'nouveau';
    }

    public function render()
    {
        $alerts = $this->activeAlerts();

        $decorated = $alerts->map(function (Alert $a) {
            $a->setAttribute('qualification_current', self::qualificationOf($a));
            $a->setAttribute('stage_current', self::stageOf($a));
            return $a;
        });

        $urgent       = $decorated->filter(fn ($a) => $a->level === 'critical' || $a->qualification_current === 'urgent');
        $enCours      = $decorated->filter(fn ($a) => $a->stage_current === 'en_traitement');
        $surveillance = $decorated->filter(fn ($a) => $a->qualification_current === 'a_surveiller');
        $queue        = $decorated->filter(fn ($a) => $a->stage_current === 'nouveau');
        $toConfirm    = $decorated->filter(fn ($a) => $a->adjudication === 'a_confirmer');

        $filtered = match ($this->filter) {
            'urgent'       => $urgent,
            'en_cours'     => $enCours,
            'surveillance' => $surveillance,
            default        => null,
        };

        $byClass = $decorated->groupBy(fn ($a) => $a->child?->classe ?? 'Sans classe')
            ->map->count()->sortDesc();
        $maxByClass = max(1, (int) $byClass->max());

        $toReview = $this->delegateMode ? collect() : ChatSession::query()
            ->with('child:id,name,classe')
            ->where('school_id', $this->school->id)
            ->whereNotNull('ended_at')
            ->where('low_confidence', true)
            ->whereDoesntHave('alert')
            ->latest('ended_at')
            ->limit(20)
            ->get();

        $followUps = $this->delegateMode ? collect() : FollowUp::query()
            ->with(['child:id,name,classe', 'responsable:id,name'])
            ->whereHas('child', fn ($q) => $q->where('school_id', $this->school->id))
            ->where('status', '!=', 'termine')
            ->whereNotNull('next_review_date')
            ->whereDate('next_review_date', '<=', now()->addDays(7)->toDateString())
            ->orderBy('next_review_date')
            ->get();

        return view('livewire.referent.overview', [
            'school'       => $this->school,
            'delegateMode' => $this->delegateMode,
            'counters'     => ['urgent' => $urgent->count(), 'en_cours' => $enCours->count(), 'surveillance' => $surveillance->count()],
            'filtered'     => $filtered,
            'queue'        => $queue->values(),
            'toConfirm'    => $toConfirm->values(),
            'toReview'     => $toReview,
            'byClass'      => $byClass,
            'maxByClass'   => $maxByClass,
            'followUps'    => $followUps,
        ]);
    }
}
