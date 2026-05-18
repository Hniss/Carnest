<?php

namespace App\DataTransferObjects;

/**
 * Agrégat statistique sur une fenêtre temporelle (sessions closes).
 *
 * Aucune donnée brute (message enfant) ne transite ici — uniquement des
 * compteurs et des moyennes calculées à partir des `chat_sessions.zone`
 * et `alerts.level`.
 */
readonly class WindowStats
{
    public function __construct(
        public ?float $averageScore,
        public int    $sessionsCount,
        public int    $alertsCount,
        public int    $criticalAlertsCount,
        public bool   $isEmpty,
        public bool   $isUnderRepresented,
    ) {
    }
}
