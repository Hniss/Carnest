<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Journal de l'espace super-admin : chaque connexion, déconnexion et tentative échouée sur un
 * compte super-admin. Jamais le mot de passe saisi, seulement le compte visé et l'adresse IP.
 */
class JournalSuperAdminAuth
{
    public function handle(Login|Logout|Failed $event): void
    {
        $user = $event instanceof Failed
            ? ($event->user ?? (isset($event->credentials['email']) ? User::where('email', mb_strtolower(trim((string) $event->credentials['email'])))->first() : null))
            : $event->user;

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            return;
        }

        [$action, $actorId, $role] = match (true) {
            $event instanceof Login  => ['superadmin.login', $user->id, 'superadmin'],
            $event instanceof Logout => ['superadmin.logout', $user->id, 'superadmin'],
            default                  => ['superadmin.login_failed', null, 'inconnu'],
        };

        AuditLog::create([
            'actor_id'    => $actorId,
            'actor_role'  => $role,
            'action'      => $action,
            'target_type' => User::class,
            'target_id'   => $user->id,
            'school_id'   => null,
            'ip'          => request()?->ip(),
            'created_at'  => now(),
        ]);
    }
}
