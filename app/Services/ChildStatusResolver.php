<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Child;

/**
 * P5 (Probleme CareNest V4) — Résout le statut enfant à 4 paliers à partir
 * du score climat 7j et des alertes récentes non résolues.
 *
 * Logique :
 *  1. Une alerte level=critical non résolue dans les 7 derniers jours → 'critique' (override absolu).
 *  2. Sinon, une alerte level=high non résolue dans les 7 derniers jours → max('a_suivre', score-mapped).
 *  3. Sinon, mappage par score :
 *      - null  → 'ok'        (pas de session récente, on n'invente pas un signal)
 *      - >= 70 → 'ok'
 *      - 50–69 → 'a_surveiller'
 *      - 30–49 → 'a_suivre'
 *      - < 30  → 'critique'
 *
 * Note RGPD : ce service ne lit JAMAIS le contenu des messages enfants — il
 * ne s'appuie que sur les niveaux d'alerte et le score climat agrégé.
 */
class ChildStatusResolver
{
    private const RANK = [
        'ok'           => 0,
        'a_surveiller' => 1,
        'a_suivre'     => 2,
        'critique'     => 3,
    ];

    public function resolve(?float $scoreEnfant, Child $child): string
    {
        $sevenDaysAgo = now()->subDays(7);

        $hasCritical = Alert::query()
            ->where('child_id', $child->id)
            ->where('level', 'critical')
            ->where('status', '!=', 'resolved')
            ->where('created_at', '>=', $sevenDaysAgo)
            ->exists();

        if ($hasCritical) {
            return 'critique';
        }

        $hasHigh = Alert::query()
            ->where('child_id', $child->id)
            ->where('level', 'high')
            ->where('status', '!=', 'resolved')
            ->where('created_at', '>=', $sevenDaysAgo)
            ->exists();

        $fromScore = $this->mapFromScore($scoreEnfant);

        if ($hasHigh) {
            return $this->maxStatus('a_suivre', $fromScore);
        }

        return $fromScore;
    }

    private function mapFromScore(?float $score): string
    {
        if ($score === null) {
            return 'ok';
        }
        if ($score >= 70) {
            return 'ok';
        }
        if ($score >= 50) {
            return 'a_surveiller';
        }
        if ($score >= 30) {
            return 'a_suivre';
        }
        return 'critique';
    }

    private function maxStatus(string $a, string $b): string
    {
        $ra = self::RANK[$a] ?? 0;
        $rb = self::RANK[$b] ?? 0;
        return $ra >= $rb ? $a : $b;
    }
}
