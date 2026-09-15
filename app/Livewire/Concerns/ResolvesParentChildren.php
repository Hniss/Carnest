<?php

namespace App\Livewire\Concerns;

use App\Models\Child;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Lot 1 §5 — un parent ne voit que SES enfants (parent_child), vérifié côté
 * serveur à chaque requête. Les enfants au consentement retiré restent listés
 * (le parent doit pouvoir consulter son consentement et ses données), jamais ceux d'autrui.
 */
trait ResolvesParentChildren
{
    /** @return Collection<int, Child> */
    protected function parentChildren(): Collection
    {
        $user = Auth::guard('web')->user();
        abort_unless($user && $user->isParent(), 403);

        return $user->children()->orderBy('name')->get();
    }

    /** Enfant du parent connecté, sinon 403 (jamais 404 : ne pas révéler l'existence). */
    protected function ownChild(?int $childId): Child
    {
        $children = $this->parentChildren();
        $child = $childId ? $children->firstWhere('id', $childId) : $children->first();
        abort_unless($child, 403);

        return $child;
    }
}
