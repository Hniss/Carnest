<?php

namespace App\Livewire\Referent;

use App\Enums\AlertType;
use App\Livewire\Concerns\ResolvesReferentAccess;
use App\Models\Child;
use App\Models\FollowUp;
use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Liste des élèves du référent (lot 1 §3.2) : filtres et recherche. */
#[Layout('layouts.app')]
class Students extends Component
{
    use ResolvesReferentAccess, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $classe = '';
    #[Url] public string $statut = '';
    #[Url] public string $anciennete = '';

    protected School $school;

    public function mount(): void
    {
        $this->school = $this->resolveReferentSchool();
        $this->denyDelegate();
    }

    public function hydrate(): void
    {
        $this->school = $this->resolveReferentSchool();
        $this->denyDelegate();
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'classe', 'statut', 'anciennete'], true)) {
            $this->resetPage();
        }
    }

    private function query(): Builder
    {
        return Child::query()
            ->where('school_id', $this->school->id)
            ->with(['latestFollowUp', 'consentingParents:id,name'])
            ->withMax('alerts as last_alert_at', 'created_at')
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->when($this->classe !== '', fn ($q) => $q->where('classe', $this->classe))
            ->when($this->statut !== '', function ($q) {
                if ($this->statut === 'aucun') {
                    $q->where(function ($qq) {
                        $qq->whereDoesntHave('followUps')
                           ->orWhereHas('latestFollowUp', fn ($f) => $f->whereIn('status', ['aucun', 'termine']));
                    });
                } else {
                    $q->whereHas('latestFollowUp', fn ($f) => $f->where('status', $this->statut));
                }
            })
            ->when($this->anciennete !== '', function ($q) {
                $days = (int) $this->anciennete;
                if ($days > 0) {
                    $q->whereHas('alerts', fn ($a) => $a->where('created_at', '>=', now()->subDays($days)));
                } else {
                    $q->whereDoesntHave('alerts');
                }
            })
            ->orderBy('name');
    }

    public function render()
    {
        $children = $this->query()->paginate(20);
        $classes  = Child::where('school_id', $this->school->id)->distinct()->orderBy('classe')->pluck('classe');

        return view('livewire.referent.students', [
            'school'   => $this->school,
            'children' => $children,
            'classes'  => $classes,
            'statuses' => FollowUp::STATUSES,
        ]);
    }
}
