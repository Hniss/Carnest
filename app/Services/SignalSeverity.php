<?php

namespace App\Services;

use App\Enums\AlertType;

/**
 * Échelle de gravité d'un signal — zone, type, niveau.
 *
 * Raison d'être : une alerte ne doit JAMAIS redescendre. Le pire moment de la
 * conversation est retenu (base fondatrice B4), et il l'est désormais pour les
 * TROIS dimensions, pas seulement pour la zone :
 *   - la zone    (green < yellow < orange < red)
 *   - le type    (ordre de priorité du prompt système, voir ci-dessous)
 *   - le niveau  (low < moderate < high < critical)
 *
 * L'ordre des types n'est pas inventé ici : il reprend mot pour mot la section
 * « PRIORISATION QUAND PLUSIEURS SIGNAUX SONT PRÉSENTS » du prompt système —
 * « danger / pensées négatives > violence (subie ou commise) / harcèlement >
 * humiliation par un adulte > isolement durable > détresse > stress / fatigue ».
 *
 * Toutes les méthodes sont monotones : elles ne rendent jamais une valeur moins
 * grave que celle déjà acquise, et rendent la valeur EXISTANTE en cas d'égalité
 * (deux types de même rang ne se remplacent pas mutuellement d'un tour à l'autre).
 */
class SignalSeverity
{
    /** Rangs de zone (identiques à CrisisDetector::maxZone). */
    private const ZONE_RANK = ['green' => 0, 'yellow' => 1, 'orange' => 2, 'red' => 3];

    /** Rangs de niveau d'alerte. */
    private const LEVEL_RANK = ['low' => 1, 'moderate' => 2, 'high' => 3, 'critical' => 4];

    /** Rangs de type — ordre de priorité du prompt système (vitaux au sommet). */
    private const TYPE_RANK = [
        'pensees_negatives'  => 6,
        'danger'             => 6,
        'harcelement'        => 5,
        'humiliation_adulte' => 4,
        'isolement'          => 3,
        'detresse'           => 2,
        'stress'             => 1,
    ];

    public static function zoneRank(?string $zone): int
    {
        return self::ZONE_RANK[(string) $zone] ?? 0;
    }

    public static function levelRank(?string $level): int
    {
        return self::LEVEL_RANK[(string) $level] ?? 0;
    }

    public static function typeRank(?string $type): int
    {
        return self::TYPE_RANK[(string) $type] ?? 0;
    }

    /** Vrai si $candidate est STRICTEMENT plus grave que $current (zone). */
    public static function zoneIsWorse(?string $current, ?string $candidate): bool
    {
        return self::zoneRank($candidate) > self::zoneRank($current);
    }

    /** Vrai si $candidate est STRICTEMENT plus grave que $current (type). */
    public static function typeIsWorse(?string $current, ?string $candidate): bool
    {
        return self::isKnownType($candidate) && self::typeRank($candidate) > self::typeRank($current);
    }

    /** Vrai si $candidate est STRICTEMENT plus grave que $current (niveau). */
    public static function levelIsWorse(?string $current, ?string $candidate): bool
    {
        return self::isKnownLevel($candidate) && self::levelRank($candidate) > self::levelRank($current);
    }

    /**
     * Type le plus grave parmi les candidats, en partant de l'existant.
     * Une valeur inconnue ou nulle est ignorée ; l'existant gagne à rang égal.
     */
    public static function maxType(?string $current, ?string ...$candidates): ?string
    {
        $retained = self::isKnownType($current) ? $current : null;

        foreach ($candidates as $candidate) {
            if (self::typeIsWorse($retained, $candidate)) {
                $retained = $candidate;
            }
        }

        return $retained;
    }

    /** Niveau le plus grave parmi les candidats, en partant de l'existant. */
    public static function maxLevel(?string $current, ?string ...$candidates): ?string
    {
        $retained = self::isKnownLevel($current) ? $current : null;

        foreach ($candidates as $candidate) {
            if (self::levelIsWorse($retained, $candidate)) {
                $retained = $candidate;
            }
        }

        return $retained;
    }

    /**
     * Niveau de repli quand aucun niveau n'a pu être calculé au fil de la session
     * (pas de message brut disponible) : mappage conservateur depuis la zone.
     */
    public static function levelFromZone(?string $zone): string
    {
        return match ($zone) {
            'red'    => 'critical',
            'orange' => 'moderate',
            'yellow' => 'low',
            default  => 'moderate',
        };
    }

    private static function isKnownType(?string $type): bool
    {
        return $type !== null && in_array($type, AlertType::values(), true) && isset(self::TYPE_RANK[$type]);
    }

    private static function isKnownLevel(?string $level): bool
    {
        return $level !== null && isset(self::LEVEL_RANK[$level]);
    }
}
