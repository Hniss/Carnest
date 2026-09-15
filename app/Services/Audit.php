<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Journal d'audit (loi 09-08) — écriture seule.
 *
 * Aucune méthode de mise à jour ni de suppression n'est exposée : une ligne
 * écrite ne se modifie jamais. Aucun contenu (message, note, résumé) n'est
 * consigné : uniquement acteur, rôle, action, cible, école, IP.
 */
final class Audit
{
    /**
     * @param array{school_id?: int|null, ip?: string|null} $meta
     */
    public static function log(string $action, ?Model $target = null, array $meta = []): AuditLog
    {
        $actor = Auth::guard('web')->user();

        $schoolId = $meta['school_id'] ?? null;
        if ($schoolId === null && $target !== null && isset($target->school_id)) {
            $schoolId = (int) $target->school_id;
        }
        if ($schoolId === null && $actor !== null) {
            $schoolId = $actor->school()?->id;
        }

        return AuditLog::create([
            'actor_id'    => $actor?->id,
            'actor_role'  => $actor?->role ?? 'system',
            'action'      => mb_substr($action, 0, 60),
            'target_type' => $target ? mb_substr($target::class, 0, 60) : null,
            'target_id'   => $target?->getKey(),
            'school_id'   => $schoolId,
            'ip'          => $meta['ip'] ?? request()?->ip(),
            'created_at'  => now(),
        ]);
    }
}
