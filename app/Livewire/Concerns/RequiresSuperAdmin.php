<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Espace super-admin : le rôle est revérifié À CHAQUE requête du composant (affichage
 * ET actions Livewire, qui ne repassent pas par le middleware de la route).
 */
trait RequiresSuperAdmin
{
    public function bootRequiresSuperAdmin(): void
    {
        $user = Auth::guard('web')->user();

        abort_unless($user !== null && $user->isSuperAdmin() && $user->deactivated_at === null, 403);
    }
}
