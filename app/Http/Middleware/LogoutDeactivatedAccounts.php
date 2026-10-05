<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte désactivé (adulte ou élève) perd l'accès à la requête suivante, même avec une
 * session déjà ouverte ou un cookie « se souvenir de moi » : déconnexion, session détruite,
 * jeton CSRF renouvelé.
 */
class LogoutDeactivatedAccounts
{
    private const GUARDS = ['web' => 'login', 'child' => 'child.login'];

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::GUARDS as $guard => $loginRoute) {
            $account = Auth::guard($guard)->user();
            if ($account === null || $account->deactivated_at === null) {
                continue;
            }

            Auth::guard($guard)->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                abort(401);
            }

            return redirect()->route($loginRoute);
        }

        return $next($request);
    }
}
