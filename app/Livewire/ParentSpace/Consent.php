<?php

namespace App\Livewire\ParentSpace;

use App\Livewire\Concerns\ResolvesParentChildren;
use App\Models\Child;
use App\Models\ParentChild;
use App\Services\Audit;
use App\Services\Notifier;
use App\Services\ParentAccountProvisioner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Consentement parental (lot 1 §5.4) : texte au nom de l'école, date, IP,
 * retrait avec confirmation → consent_withdrawn_at, désactivation immédiate
 * du compte enfant ET du compte parent s'il ne reste aucun enfant consenti
 * (spec §5.1), notification au référent et à l'administration, trace audit.
 */
#[Layout('layouts.parent')]
class Consent extends Component
{
    use ResolvesParentChildren;

    public ?string $flash = null;

    public function withdraw(int $childId, Notifier $notifier, ParentAccountProvisioner $provisioner): void
    {
        $child = $this->ownChild($childId);
        $parent = Auth::user();
        $pivot = $child->parents()->where('users.id', $parent->id)->first()->pivot;

        abort_unless($pivot->consent_given && $pivot->consent_withdrawn_at === null, 422);

        DB::transaction(function () use ($child, $parent, $provisioner) {
            $parent->children()->updateExistingPivot($child->id, ['consent_withdrawn_at' => now()]);
            if (! $child->fresh()->hasActiveConsent()) {
                $child->forceFill(['deactivated_at' => now()])->save();
            }
            // Spec §5.1 — le compte parent se désactive s'il ne reste aucun enfant consenti.
            $provisioner->syncActivation($parent);
        });

        Audit::log('parent.consent.withdraw', $child, ['school_id' => $child->school_id]);

        $school = $child->school;
        $recipients = $school->users()->where('users.role', '!=', 'parent')->whereNull('users.deactivated_at')->get();
        foreach ($recipients as $u) {
            $notifier->notify(
                $u, 'consentement', 'Retrait de consentement parental',
                'Le consentement pour ' . $child->name . ' a été retiré : le compte élève est désactivé.',
                $u->isReferent() ? '/dashboard-referent/eleves/' . $child->id : '/children/' . $child->id
            );
        }

        $this->flash = 'Votre consentement a été retiré. Le compte de ' . $child->name . ' est désactivé.';
    }

    public function render()
    {
        $parent = Auth::user();
        $children = $this->parentChildren()->load('school');

        $rows = $children->map(fn (Child $c) => [
            'child'  => $c,
            'pivot'  => $c->pivot,
            'text'   => $c->pivot->consent_text ?: ParentChild::consentText($c->school->name),
            'active' => $c->pivot->consent_given && $c->pivot->consent_withdrawn_at === null,
        ]);

        return view('livewire.parent-space.consent', ['rows' => $rows, 'parent' => $parent]);
    }
}
