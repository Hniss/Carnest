<?php

namespace Tests\Feature\Lot2;

use App\Livewire\Admin\Settings;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/** D8 — le plafond quotidien accepte 0 (= désactivé) depuis les paramètres de l'administration. */
class SettingsTokenCapZeroTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_admin_can_set_daily_token_cap_to_zero(): void
    {
        $school = School::factory()->create();
        $this->actingAs($this->makeAdmin($school));

        Livewire::test(Settings::class)
            ->set('dailyTokenCap', 0)
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('school_settings', ['school_id' => $school->id, 'daily_token_cap' => 0]);
    }
}
