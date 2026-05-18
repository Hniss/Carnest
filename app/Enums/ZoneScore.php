<?php

namespace App\Enums;

/**
 * Mapping zone (Kuypers — Zones of Regulation) → score numérique 0–100.
 *
 * Source de vérité unique du mapping zone→score utilisé par :
 *  - App\Jobs\ProcessSessionClosure       (calcul score climat 7j)
 *  - App\Services\WellbeingTrendResolver  (agrégation longitudinale)
 *  - Dashboard admin (score climat école)
 *
 * Conformité (CONTEXT.md §4) :
 *  - green=100, yellow=70, orange=35, red=0.
 *  - Aucune dépendance au contenu des messages enfants.
 */
enum ZoneScore: int
{
    case Green  = 100;
    case Yellow = 70;
    case Orange = 35;
    case Red    = 0;

    /**
     * Convertit le string zone (tel qu'enregistré dans chat_sessions.zone)
     * en score numérique. Retourne 0 pour une zone inconnue (fail-safe :
     * mieux vaut surestimer la gravité qu'ignorer une zone exotique).
     */
    public static function fromZone(string $zone): int
    {
        return match (strtolower($zone)) {
            'green'  => self::Green->value,
            'yellow' => self::Yellow->value,
            'orange' => self::Orange->value,
            'red'    => self::Red->value,
            default  => 0,
        };
    }
}
