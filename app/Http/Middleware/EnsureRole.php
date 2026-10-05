<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot 1 — cloisonnement par rôle côté serveur.
 * Usage : `role:admin`, `role:referent`, `role:parent`, `role:superadmin`, combinable `role:admin,referent`.
 *
 * Décision du 2026-10-05 (« chaque profil a sa redirection correcte vers son profil ») :
 * une PAGE d'un autre profil ouverte dans le navigateur renvoie vers l'accueil de son propre
 * profil ; toute autre requête (envoi de formulaire, appel JSON) reste refusée en 403.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user('web');

        abort_unless($user !== null, 403);

        if (! in_array($user->role, $roles, true)) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                return new RedirectResponse(url($user->homePath()));
            }
            abort(403);
        }

        return $next($request);
    }
}
