<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Où envoyer un adulte après sa connexion, ou quand il ouvre un lien de notification :
 * jamais sur une page que son rôle n'a pas le droit d'ouvrir.
 *
 * Laravel mémorise en session l'adresse demandée avant la connexion (`url.intended`). Sur un
 * poste partagé, cette adresse peut appartenir à un AUTRE rôle : la page d'un référent qui
 * s'est déconnecté, puis un administrateur qui se connecte, et c'était un 403. L'adresse
 * mémorisée n'est donc suivie que si le rôle de l'utilisateur y a accès ; sinon il arrive sur
 * l'accueil de son rôle (User::homePath()).
 *
 * L'accès se lit sur la route elle-même (middlewares `role:`, `auth:<guard>`, `guest`) : une
 * nouvelle page est couverte sans rien ajouter ici. Seule exception, l'espace référent ouvert
 * à l'administration (`role:referent,admin`) : la page refuse un administrateur sans
 * délégation active (ResolvesReferentAccess) — même règle ici.
 */
final class RoleRedirect
{
    /** Destination après connexion. Consomme l'adresse mémorisée, qu'elle soit suivie ou non. */
    public static function afterLogin(User $user, ?string $default = null): string
    {
        $intended = session()->pull('url.intended');

        if (is_string($intended) && $intended !== '' && self::allows($user, $intended)) {
            return $intended;
        }

        return $default ?? $user->homePath();
    }

    /** Lien d'une notification : suivi s'il est encore ouvert à l'utilisateur, sinon l'accueil de son rôle. */
    public static function link(User $user, ?string $link): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }

        return self::allows($user, $link) ? $link : $user->homePath();
    }

    /** Vrai si le rôle de l'utilisateur peut ouvrir cette adresse de l'application. */
    public static function allows(User $user, string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }
        if (isset($parts['host']) && strcasecmp($parts['host'], request()->getHost()) !== 0) {
            return false;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($parts['path'] ?? '/', 'GET'));
        } catch (\Throwable) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if ($middleware === 'guest' || str_starts_with($middleware, 'guest:')) {
                return false;
            }
            if (str_starts_with($middleware, 'auth:') && ! in_array('web', explode(',', substr($middleware, 5)), true)) {
                return false;
            }
            if (str_starts_with($middleware, 'role:') && ! in_array($user->role, explode(',', substr($middleware, 5)), true)) {
                return false;
            }
        }

        if (str_starts_with((string) $route->getName(), 'referent.') && ! $user->isReferent()) {
            return $user->delegatedSchools()->isNotEmpty();
        }

        return true;
    }
}
