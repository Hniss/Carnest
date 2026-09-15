<?php

namespace App\Enums;

/**
 * D7 (MVP v3) — Nomenclature UNIQUE des types d'alerte (7 valeurs).
 *
 * Source de vérité pour :
 *  - le prompt système et l'analyse de clôture (GeminiService)
 *  - le filet déterministe (CrisisDetector)
 *  - les résolveurs (AlertLevelResolver, ChildContextBuilder)
 *  - les libellés affichés côté administration
 *  - l'enum SQL alerts.type (migration 2026_09_15_000001_unify_alert_types)
 *
 * `tristesse` a disparu (fusionné dans `detresse`).
 * Types VITAUX (alerte immédiate, référent + administration) : danger, pensees_negatives.
 */
enum AlertType: string
{
    case Harcelement       = 'harcelement';
    case Detresse          = 'detresse';
    case PenseesNegatives  = 'pensees_negatives';
    case Danger            = 'danger';
    case Isolement         = 'isolement';
    case Stress            = 'stress';
    case HumiliationAdulte = 'humiliation_adulte';

    public function label(): string
    {
        return match ($this) {
            self::Harcelement       => 'Harcèlement',
            self::Detresse          => 'Détresse',
            self::PenseesNegatives  => 'Pensées négatives',
            self::Danger            => 'Danger',
            self::Isolement         => 'Isolement',
            self::Stress            => 'Stress chronique',
            self::HumiliationAdulte => 'Humiliation par un adulte',
        };
    }

    /** Signal vital : alerte immédiate, quel que soit l'horaire (D5). */
    public function isVital(): bool
    {
        return in_array($this, [self::Danger, self::PenseesNegatives], true);
    }

    /** Type « grave » : autorise le rappel explicite doux dans la mémoire inter-sessions. */
    public function isSerious(): bool
    {
        return $this !== self::Stress;
    }

    /** @return string[] Les 7 valeurs, dans l'ordre canonique. */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }

    /** @return string[] */
    public static function vitalValues(): array
    {
        return array_values(array_map(
            fn (self $t) => $t->value,
            array_filter(self::cases(), fn (self $t) => $t->isVital())
        ));
    }

    /** @return string[] */
    public static function seriousValues(): array
    {
        return array_values(array_map(
            fn (self $t) => $t->value,
            array_filter(self::cases(), fn (self $t) => $t->isSerious())
        ));
    }

    /** Libellé FR pour une valeur brute (base, résultat IA) ; « Inconnu » si hors nomenclature. */
    public static function labelFor(?string $value): string
    {
        $case = $value !== null ? self::tryFrom($value) : null;

        return $case?->label() ?? 'Inconnu';
    }
}
