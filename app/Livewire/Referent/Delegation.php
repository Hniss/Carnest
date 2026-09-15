<?php

namespace App\Livewire\Referent;

use App\Livewire\Concerns\ResolvesReferentAccess;
use App\Models\ReferentDelegation;
use App\Models\School;
use App\Models\User;
use App\Services\Audit;
use App\Services\Notifier;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Délégation temporaire du référent (lot 1 §3.6) : créer, activer, révoquer, historique. Tracé dans audit_logs. */
#[Layout('layouts.app')]
class Delegation extends Component
{
    use ResolvesReferentAccess;

    public ?int $delegateId = null;
    public ?string $startDate = null;
    public ?string $endDate = null;
    public ?string $flash = null;

    protected School $school;

    public function mount(): void
    {
        $this->school = $this->resolveReferentSchool();
        $this->denyDelegate();
        $this->startDate = now()->toDateString();
        $this->endDate = now()->addDays(7)->toDateString();
    }

    public function hydrate(): void
    {
        $this->school = $this->resolveReferentSchool();
        $this->denyDelegate();
    }

    private function candidates()
    {
        return User::query()
            ->where('role', '!=', 'parent')
            ->where('id', '!=', Auth::id())
            ->whereHas('schools')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);
    }

    public function create(): void
    {
        $this->validate([
            'delegateId' => ['required', 'integer', 'exists:users,id'],
            'startDate'  => ['required', 'date'],
            'endDate'    => ['required', 'date', 'after_or_equal:startDate'],
        ], [], ['delegateId' => 'compte délégué', 'startDate' => 'date de début', 'endDate' => 'date de fin']);

        abort_unless($this->candidates()->contains('id', $this->delegateId), 403);

        $delegation = ReferentDelegation::create([
            'school_id'   => $this->school->id,
            'referent_id' => Auth::id(),
            'delegate_id' => $this->delegateId,
            'start_date'  => $this->startDate,
            'end_date'    => $this->endDate,
        ]);
        Audit::log('referent.delegation.create', $delegation);
        $this->reset('delegateId');
        $this->flash = 'Délégation créée. Activez-la pour ouvrir l\'accès.';
    }

    private function own(int $id): ReferentDelegation
    {
        $d = ReferentDelegation::find($id);
        abort_unless($d, 404);
        abort_unless($d->school_id === $this->school->id, 403);
        return $d;
    }

    public function activate(int $id): void
    {
        $d = $this->own($id);
        abort_if($d->revoked_at !== null, 422);
        $d->update(['activated_at' => now()]);
        Audit::log('referent.delegation.activate', $d);
        app(Notifier::class)->notify(
            $d->delegate, 'delegation', 'Délégation activée',
            'Vous avez accès aux alertes actives de ' . $this->school->name . ' du ' . $d->start_date->format('d/m/Y') . ' au ' . $d->end_date->format('d/m/Y') . '.',
            '/dashboard-referent'
        );
        $this->flash = 'Délégation activée.';
    }

    public function revoke(int $id): void
    {
        $d = $this->own($id);
        $d->update(['revoked_at' => now()]);
        Audit::log('referent.delegation.revoke', $d);
        $this->flash = 'Délégation révoquée.';
    }

    public function render()
    {
        return view('livewire.referent.delegation', [
            'school'      => $this->school,
            'candidates'  => $this->candidates(),
            'delegations' => $this->school->delegations()->with('delegate:id,name,email')->latest()->get(),
        ]);
    }
}
