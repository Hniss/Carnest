<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot 1 — cloisonnement par rôle côté serveur.
 * Usage : `role:admin`, `role:referent`, `role:parent`, combinable `role:admin,referent`.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user('web');

        abort_unless($user !== null, 403);
        abort_unless(in_array($user->role, $roles, true), 403);

        return $next($request);
    }
}
