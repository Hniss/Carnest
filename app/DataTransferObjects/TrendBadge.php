<?php

namespace App\DataTransferObjects;

/**
 * Badge de tendance affiché sur le profil enfant.
 *
 * - direction : 'improving' | 'stable' | 'worsening' | 'unknown'
 * - delta     : current - previous, null si direction='unknown'
 * - label     : libellé FR court prêt pour la vue (ex. « en amélioration »)
 * - reason    : 'insufficient_data' | 'normal' | 'no_baseline'
 */
readonly class TrendBadge
{
    public function __construct(
        public string $direction,
        public ?float $delta,
        public string $label,
        public string $reason,
    ) {
    }
}
