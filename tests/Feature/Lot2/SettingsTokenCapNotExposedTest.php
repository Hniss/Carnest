<?php

namespace Tests\Feature\Lot2;

use App\Livewire\Admin\Settings;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Services\TokenBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

/**
 * D8 — le plafond quotidien de tokens est un réglage interne CareNest : il n'est
 * jamais visible ni modifiable par l'administration de l'établissement.
 *
 * (Remplace SettingsTokenCapZeroTest, qui vérifiait la saisie du plafond depuis
 * cet écran. La désactivation par un plafond à 0 reste couverte au niveau du
 * budget : ChatTokenCapTest::test_cap_zero_disables_the_limit.)
 */
class SettingsTokenCapNotExposedTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_school_admin_never_sees_the_daily_token_cap(): void
    {
        $school = School::factory()->create();
        $this->actingAs($this->makeAdmin($school));

        $component = Livewire::test(Settings::class);

        $this->assertFalse(property_exists(Settings::class, 'dailyTokenCap'));
        $component->assertDontSee('Plafond')
            ->assertDontSee('plafond')
            ->assertDontSee('dailyTokenCap')
            ->assertDontSee('token');
    }

    public function test_saving_settings_leaves_the_cap_untouched(): void
    {
        $school = School::factory()->create();
        SchoolSetting::updateOrCreate(['school_id' => $school->id], ['daily_token_cap' => 42000]);
        $this->actingAs($this->makeAdmin($school));

        Livewire::test(Settings::class)
            ->set('schoolHoursStart', '08:30')
            ->set('schoolHoursEnd', '16:30')
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('school_settings', [
            'school_id' => $school->id, 'school_hours_start' => '08:30', 'daily_token_cap' => 42000,
        ]);
        // La colonne reste lue par le budget, pilotée côté CareNest.
        $this->assertSame(42000, app(TokenBudget::class)->cap($school->fresh()));
    }

    public function test_default_cap_is_still_ten_thousand_for_a_new_school(): void
    {
        $school = School::factory()->create();
        $this->actingAs($this->makeAdmin($school));

        Livewire::test(Settings::class)->call('save')->assertHasNoErrors();

        $this->assertSame(TokenBudget::DEFAULT_CAP, app(TokenBudget::class)->cap($school->fresh()));
        $this->assertDatabaseHas('school_settings', ['school_id' => $school->id, 'daily_token_cap' => 10000]);
    }
}
