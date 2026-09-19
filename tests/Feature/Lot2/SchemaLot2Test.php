<?php

namespace Tests\Feature\Lot2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Lot 2 — colonnes ajoutées (escalade, plafond). */
class SchemaLot2Test extends TestCase
{
    use RefreshDatabase;

    public function test_lot2_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('alerts', 'escalation_exhausted_at'));
        $this->assertTrue(Schema::hasColumn('children', 'high_usage_notified_on'));
    }
}
