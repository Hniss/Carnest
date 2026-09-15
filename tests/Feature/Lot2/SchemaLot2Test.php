<?php

namespace Tests\Feature\Lot2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Lot 2 — colonnes et tables ajoutées (escalade, plafond, battements, versions de prompt). */
class SchemaLot2Test extends TestCase
{
    use RefreshDatabase;

    public function test_lot2_columns_and_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('alerts', 'escalation_exhausted_at'));
        $this->assertTrue(Schema::hasColumn('children', 'high_usage_notified_on'));
        $this->assertTrue(Schema::hasColumns('pager_heartbeats', ['worker', 'beat_at']));
        $this->assertTrue(Schema::hasColumns('prompt_versions', ['version', 'hash', 'target_model', 'notes', 'created_at']));
    }
}
