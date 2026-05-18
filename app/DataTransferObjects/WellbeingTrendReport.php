<?php

namespace App\DataTransferObjects;

use App\Models\ChatSession;

/**
 * Rapport complet de suivi psychique longitudinal pour un enfant.
 *
 * Consommé par App\Livewire\Admin\ChildProfile. Toutes les valeurs sont
 * déjà agrégées et anonymisées — la vue n'a aucune logique à recalculer.
 */
readonly class WellbeingTrendReport
{
    /**
     * @param array<int, ?float> $weeklySparkline 8 entrées, ordre chrono ASC.
     */
    public function __construct(
        public ?float       $currentScore,
        public string       $currentStatus,
        public WindowStats  $shortTerm,
        public WindowStats  $longTerm,
        public TrendBadge   $shortTermTrend,
        public TrendBadge   $longTermTrend,
        public int          $worseningStreak,
        public bool         $worseningSignal,
        public array        $weeklySparkline,
        public ?ChatSession $lastSession,
    ) {
    }
}
