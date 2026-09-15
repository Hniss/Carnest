<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;

/**
 * D8 (MVP v3) — plafond de tokens par enfant et par jour.
 *
 * Le plafond n'est JAMAIS bloquant en zone jaune/orange/rouge ou en présence
 * d'une alerte : la décision d'appliquer la clôture appartient à l'appelant
 * (ChatInterface). Ici on ne fait que mesurer. 0 = désactivé.
 */
class TokenBudget
{
    public const DEFAULT_CAP = 10000;

    /** Somme de `chat_sessions.tokens_used` des sessions ouvertes aujourd'hui (fuseau de l'application). */
    public function usedToday(Child $child): int
    {
        return (int) ChatSession::query()
            ->where('child_id', $child->id)
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->sum('tokens_used');
    }

    public function cap(School $school): int
    {
        $cap = $school->setting?->daily_token_cap;

        return $cap === null ? self::DEFAULT_CAP : max(0, (int) $cap);
    }

    public function isExceeded(Child $child): bool
    {
        $school = $child->school;
        if ($school === null) {
            return false;
        }

        $cap = $this->cap($school);
        if ($cap <= 0) {
            return false;
        }

        return $this->usedToday($child) >= $cap;
    }
}
