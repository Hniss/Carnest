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
 * Reprend le traitement de la migration de données, à la demande, pour une
 * école ou pour toutes : personne ne doit jamais écrire de SQL à la main pour
 * débloquer un plafond journalier (le réglage n'est plus exposé à l'école).
 *
 * Elle va délibérément plus loin que la migration : elle vide aussi les
 * compteurs de la JOURNÉE EN COURS, sans condition de date de bascule. C'est
 * le seul moyen de débloquer une installation restée sur l'ancien code après
 * le commit a0f956f, dont les compteurs de l'ancienne unité portent des dates
 * postérieures à la borne de la migration.
 */
class ResetUsageCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Date figée de « maintenant » : les tests ne doivent pas dépendre du jour d'exécution. */
    private const NOW = '2026-09-25 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
        Carbon::setTestNow(self::NOW);

        return $session;
    }

    public function test_force_resets_every_school_and_reports_what_changed(): void
    {
        $bloquee    = $this->makeSchool(50);
        $desactivee = $this->makeSchool(0);
        $ancienne   = $this->makeSession($bloquee, '2026-09-20 10:00:00', 4013);
        $veille     = $this->makeSession($bloquee, '2026-09-24 10:00:00', 78);

        $this->artisan('carenest:reset-usage', ['--force' => true])
            ->expectsOutputToContain('1 école(s)')
            ->expectsOutputToContain('antérieurs au 21/09/2026 13:58 remis à zéro : 1 session(s)')
            ->assertSuccessful();

        $this->assertSame(TokenBudget::DEFAULT_CAP, (int) $bloquee->fresh()->setting->daily_token_cap);
        $this->assertSame(0, (int) $desactivee->fresh()->setting->daily_token_cap);
        $this->assertSame(0, (int) $ancienne->fresh()->tokens_used);
        $this->assertSame(78, (int) $veille->fresh()->tokens_used, "Une session d'un autre jour, postérieure à la bascule, n'est pas touchée.");
    }

    /**
     * LE CAS RÉEL : l'installation a continué à tourner avec l'ancien code après
     * le commit a0f956f. Les compteurs sont dans l'ancienne unité (~4 000 par
     * échange) MAIS datés d'après la borne : la migration ne les voit pas, et
     * `TokenBudget::usedToday()` les somme quand même. Seule la commande débloque.
     */
    public function test_todays_counters_are_cleared_even_after_the_cut_off(): void
    {
        $school = $this->makeSchool(TokenBudget::DEFAULT_CAP);
        $dujour = $this->makeSession($school, '2026-09-25 08:30:00', 12039);
        $child  = $dujour->child;

        $this->assertTrue(
            app(TokenBudget::class)->isExceeded($child),
            "Pré-condition : l'enfant est bien bloqué par des compteurs de l'ancienne unité."
        );

        $this->artisan('carenest:reset-usage', ['--force' => true])
            ->expectsOutputToContain('journée en cours remis à zéro : 1 session(s)')
            ->assertSuccessful();

        $this->assertSame(0, (int) $dujour->fresh()->tokens_used);
        $this->assertFalse(
            app(TokenBudget::class)->isExceeded($child->fresh()),
            "Après la commande, l'enfant doit pouvoir reprendre une conversation le jour même."
        );
    }

    public function test_the_school_option_limits_the_perimeter(): void
    {
        $ciblee  = $this->makeSchool(50);
        $autre   = $this->makeSchool(50);
        $sCiblee = $this->makeSession($ciblee, '2026-09-20 10:00:00', 4013);
        $sAutre  = $this->makeSession($autre, '2026-09-20 10:00:00', 4013);
        $jCiblee = $this->makeSession($ciblee, '2026-09-25 08:00:00', 9000);
        $jAutre  = $this->makeSession($autre, '2026-09-25 08:00:00', 9000);

        $this->artisan('carenest:reset-usage', ['--school' => $ciblee->id, '--force' => true])
            ->assertSuccessful();

        $this->assertSame(TokenBudget::DEFAULT_CAP, (int) $ciblee->fresh()->setting->daily_token_cap);
        $this->assertSame(0, (int) $sCiblee->fresh()->tokens_used);
        $this->assertSame(0, (int) $jCiblee->fresh()->tokens_used);

        $this->assertSame(50, (int) $autre->fresh()->setting->daily_token_cap, 'Une école hors périmètre ne doit pas bouger.');
        $this->assertSame(4013, (int) $sAutre->fresh()->tokens_used, 'Une session hors périmètre ne doit pas bouger.');
        $this->assertSame(9000, (int) $jAutre->fresh()->tokens_used, 'Une session du jour hors périmètre ne doit pas bouger.');
    }

    /** Les deux chiffres annoncés portent sur des sessions distinctes, jamais les mêmes. */
    public function test_the_two_reported_counts_never_overlap(): void
    {
        $school = $this->makeSchool(TokenBudget::DEFAULT_CAP);
        $this->makeSession($school, '2026-09-20 10:00:00', 4013);
        $this->makeSession($school, '2026-09-25 08:00:00', 9000);

        $this->artisan('carenest:reset-usage', ['--force' => true])
            ->expectsOutputToContain('antérieurs au 21/09/2026 13:58 remis à zéro : 1 session(s)')
            ->expectsOutputToContain('journée en cours remis à zéro : 1 session(s)')
            ->assertSuccessful();
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
        $dujour = $this->makeSession($school, '2026-09-25 08:00:00', 9000);

        $this->artisan('carenest:reset-usage')
            ->expectsConfirmation('Confirmer ?', 'no')
            ->assertSuccessful();

        $this->assertSame(50, (int) $school->fresh()->setting->daily_token_cap, 'Un refus ne doit rien modifier.');
        $this->assertSame(9000, (int) $dujour->fresh()->tokens_used, 'Un refus ne doit pas vider la journée en cours.');
    }
}
