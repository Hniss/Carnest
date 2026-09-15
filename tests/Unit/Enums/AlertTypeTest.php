<?php

namespace Tests\Unit\Enums;

use App\Enums\AlertType;
use PHPUnit\Framework\TestCase;

/**
 * D7 (MVP v3) — Nomenclature unique des types d'alerte (7 valeurs).
 * `tristesse` a disparu (fusionné dans `detresse`).
 * Types vitaux = danger + pensees_negatives.
 */
class AlertTypeTest extends TestCase
{
    public function test_exposes_exactly_the_seven_unified_values(): void
    {
        $this->assertSame(
            ['harcelement', 'detresse', 'pensees_negatives', 'danger', 'isolement', 'stress', 'humiliation_adulte'],
            AlertType::values()
        );
        $this->assertNotContains('tristesse', AlertType::values());
    }

    public function test_labels_are_french_with_accents(): void
    {
        $this->assertSame('Harcèlement', AlertType::Harcelement->label());
        $this->assertSame('Détresse', AlertType::Detresse->label());
        $this->assertSame('Pensées négatives', AlertType::PenseesNegatives->label());
        $this->assertSame('Danger', AlertType::Danger->label());
        $this->assertSame('Isolement', AlertType::Isolement->label());
        $this->assertSame('Stress chronique', AlertType::Stress->label());
        $this->assertSame('Humiliation par un adulte', AlertType::HumiliationAdulte->label());
    }

    public function test_only_danger_and_negative_thoughts_are_vital(): void
    {
        $vital = array_map(fn (AlertType $t) => $t->value, array_filter(AlertType::cases(), fn ($t) => $t->isVital()));
        $this->assertEqualsCanonicalizing(['danger', 'pensees_negatives'], $vital);
        $this->assertEqualsCanonicalizing(['danger', 'pensees_negatives'], AlertType::vitalValues());
    }

    public function test_serious_values_include_vitals_and_exclude_stress(): void
    {
        $serious = AlertType::seriousValues();
        $this->assertContains('danger', $serious);
        $this->assertContains('pensees_negatives', $serious);
        $this->assertContains('harcelement', $serious);
        $this->assertContains('humiliation_adulte', $serious);
        $this->assertNotContains('stress', $serious);
    }

    public function test_label_for_string_falls_back_to_readable_text(): void
    {
        $this->assertSame('Pensées négatives', AlertType::labelFor('pensees_negatives'));
        $this->assertSame('Inconnu', AlertType::labelFor('inconnu'));
        $this->assertSame('Inconnu', AlertType::labelFor(null));
    }
}
