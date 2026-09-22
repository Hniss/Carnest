<?php

namespace Tests\Feature\Lot2;

use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Services\TokenBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D8 — commande `carenest:reset-usage`.
 *
 * Même traitement que la migration de données, à la demande, pour une école ou
 * pour toutes : personne ne doit jamais écrire de SQL à la main pour débloquer
 * un plafond journalier (le réglage n'est plus exposé à l'établissement).
 */
class ResetUsageCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchool(int $cap): School
    {
        $school = School::factory()->create();
        SchoolSetting::updateOrCreate(['school_id' => $school->id], ['daily_token_cap' => $cap]);

        return $school;
    }

    private function makeSession(School $school, string $createdAt, int $tokens): ChatSession
    {
        $child = Child::factory()->for($school)->create(['age' => 10]);

        Carbon::setTestNow($createdAt);
        $session = ChatSession::create([
            'child_id'         => $child->id,
            'school_id'        => $school->id,
            'started_at'       => now(),
            'last_activity_at' => now(),
            'tokens_used'      => $tokens,
        ]);
        Carbon::setTestNow();

        return $session;
    }

    public function test_force_resets_every_school_and_reports_what_changed(): void
    {
        $bloquee  = $this->makeSchool(50);
        $desactivee = $this->makeSchool(0);
        $ancienne = $this->makeSession($bloquee, '2026-09-20 10:00:00', 4013);
        $recente  = $this->makeSession($bloquee, '2026-09-22 10:00:00', 78);

        $this->artisan('carenest:reset-usage', ['--force' => true])
            ->expectsOutputToContain('1 école(s)')
            ->expectsOutputToContain('1 session(s)')
            ->assertSuccessful();

        $this->assertSame(TokenBudget::DEFAULT_CAP, (int) $bloquee->fresh()->setting->daily_token_cap);
        $this->assertSame(0, (int) $desactivee->fresh()->setting->daily_token_cap);
        $this->assertSame(0, (int) $ancienne->fresh()->tokens_used);
        $this->assertSame(78, (int) $recente->fresh()->tokens_used);
    }

    public function test_the_school_option_limits_the_perimeter(): void
    {
        $ciblee  = $this->makeSchool(50);
        $autre   = $this->makeSchool(50);
        $sCiblee = $this->makeSession($ciblee, '2026-09-20 10:00:00', 4013);
        $sAutre  = $this->makeSession($autre, '2026-09-20 10:00:00', 4013);

        $this->artisan('carenest:reset-usage', ['--school' => $ciblee->id, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(TokenBudget::DEFAULT_CAP, (int) $ciblee->fresh()->setting->daily_token_cap);
        $this->assertSame(0, (int) $sCiblee->fresh()->tokens_used);

        $this->assertSame(50, (int) $autre->fresh()->setting->daily_token_cap, 'Une école hors périmètre ne doit pas bouger.');
        $this->assertSame(4013, (int) $sAutre->fresh()->tokens_used, 'Une session hors périmètre ne doit pas bouger.');
    }

    public function test_an_unknown_school_fails_without_touching_anything(): void
    {
        $school = $this->makeSchool(50);

        $this->artisan('carenest:reset-usage', ['--school' => 999999, '--force' => true])
            ->expectsOutputToContain('999999')
            ->assertFailed();

        $this->assertSame(50, (int) $school->fresh()->setting->daily_token_cap);
    }

    /**
     * Hors terminal (hook de déploiement, cron, CI) la confirmation ne peut pas
     * être posée : la commande doit le dire et échouer, jamais rendre un succès
     * silencieux qui laisserait l'installation bloquée.
     */
    public function test_a_non_interactive_run_without_force_fails_instead_of_doing_nothing(): void
    {
        $school = $this->makeSchool(50);

        $this->artisan('carenest:reset-usage', ['--no-interaction' => true])
            ->expectsOutputToContain('--force')
            ->assertFailed();

        $this->assertSame(50, (int) $school->fresh()->setting->daily_token_cap);
    }

    public function test_without_force_the_command_asks_and_a_refusal_changes_nothing(): void
    {
        $school = $this->makeSchool(50);

        $this->artisan('carenest:reset-usage')
            ->expectsConfirmation('Confirmer ?', 'no')
            ->assertSuccessful();

        $this->assertSame(50, (int) $school->fresh()->setting->daily_token_cap, 'Un refus ne doit rien modifier.');
    }
}
