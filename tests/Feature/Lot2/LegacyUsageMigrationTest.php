<?php

namespace Tests\Feature\Lot2;

use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Services\TokenBudget;
use App\Services\UsageReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D8 — migration de données `2026_09_22_000001_reset_legacy_token_usage`.
 *
 * Le commit a0f956f a changé l'unité du compteur ET du plafond sans migrer la
 * moindre donnée : toute installation déjà utilisée restait coupée au premier
 * échange. Ces tests verrouillent les deux comportements de la reprise, et le
 * fait qu'elle ne touche à rien d'autre.
 */
class LegacyUsageMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Rejoue la migration de données telle qu'elle sera exécutée par `php artisan migrate`. */
    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_22_000001_reset_legacy_token_usage.php');
        $migration->up();
    }

    private function makeSchool(?int $cap): School
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

    public function test_a_cap_inherited_from_the_old_unit_goes_back_to_the_default(): void
    {
        // 50 : valeur exacte que le protocole de test du README faisait poser.
        $school = $this->makeSchool(50);

        $this->runMigration();

        $this->assertSame(
            TokenBudget::DEFAULT_CAP,
            (int) $school->fresh()->setting->daily_token_cap,
            'Un plafond de 50 ne permet pas un seul échange dans la nouvelle unité : il doit repasser au défaut.'
        );
    }

    public function test_a_cap_disabled_at_zero_is_preserved(): void
    {
        $school = $this->makeSchool(0);

        $this->runMigration();

        $this->assertSame(
            0,
            (int) $school->fresh()->setting->daily_token_cap,
            '0 signifie « plafond désactivé » : c\'est un choix explicite, jamais une valeur héritée.'
        );
    }

    /** Le seuil est un plancher strict : 600 est déjà un réglage vraisemblable. */
    public function test_a_legitimate_cap_is_left_untouched(): void
    {
        $schools = [];
        foreach ([UsageReset::LEGACY_CAP_THRESHOLD, 1000, TokenBudget::DEFAULT_CAP, 42000] as $cap) {
            $schools[$cap] = $this->makeSchool($cap);
        }

        $this->runMigration();

        foreach ($schools as $cap => $school) {
            $this->assertSame(
                $cap,
                (int) $school->fresh()->setting->daily_token_cap,
                "Le plafond {$cap} est exploitable dans la nouvelle unité : la migration ne doit pas y toucher."
            );
        }
    }

    public function test_counters_written_before_the_unit_changed_are_zeroed(): void
    {
        $school  = $this->makeSchool(TokenBudget::DEFAULT_CAP);
        $ancienne = $this->makeSession($school, '2026-09-21 09:00:00', 4013);

        $this->runMigration();

        $this->assertSame(
            0,
            (int) $ancienne->fresh()->tokens_used,
            'Un compteur de l\'ancienne unité n\'est pas convertible : il repart de zéro.'
        );
    }

    public function test_counters_written_after_the_unit_changed_are_intact(): void
    {
        $school     = $this->makeSchool(TokenBudget::DEFAULT_CAP);
        $posterieure = $this->makeSession($school, '2026-09-21 15:00:00', 39);

        $this->runMigration();

        $this->assertSame(
            39,
            (int) $posterieure->fresh()->tokens_used,
            'Une session postérieure à la correction compte déjà dans la bonne unité.'
        );
    }

    /**
     * Frontière entre la migration et la commande : une session du JOUR, écrite
     * dans l'ancienne unité par une installation restée sur l'ancien code, est
     * postérieure à la borne. La migration automatique ne doit PAS y toucher —
     * elle reste prudente ; c'est `carenest:reset-usage`, geste humain explicite,
     * qui la nettoie (voir `ResetUsageCommandTest`).
     */
    public function test_the_migration_leaves_a_counter_of_the_current_day_untouched(): void
    {
        $school = $this->makeSchool(TokenBudget::DEFAULT_CAP);
        $dujour = $this->makeSession($school, now()->toDateTimeString(), 12039);

        $this->runMigration();

        $this->assertSame(
            12039,
            (int) $dujour->fresh()->tokens_used,
            'La migration ne traite que ce dont elle est certaine : tout ce qui précède la bascule.'
        );
    }

    public function test_the_migration_can_be_replayed_without_damage(): void
    {
        $legacy      = $this->makeSchool(50);
        $legitime    = $this->makeSchool(2000);
        $ancienne    = $this->makeSession($legacy, '2026-09-20 11:00:00', 8000);
        $posterieure = $this->makeSession($legitime, '2026-09-22 08:00:00', 117);

        $this->runMigration();
        $this->runMigration();
        $this->runMigration();

        $this->assertSame(TokenBudget::DEFAULT_CAP, (int) $legacy->fresh()->setting->daily_token_cap);
        $this->assertSame(2000, (int) $legitime->fresh()->setting->daily_token_cap);
        $this->assertSame(0, (int) $ancienne->fresh()->tokens_used);
        $this->assertSame(117, (int) $posterieure->fresh()->tokens_used);
    }

    /** Le retour arrière est impossible et assumé : `down()` ne doit rien casser ni rien inventer. */
    public function test_down_is_an_honest_no_op(): void
    {
        $school   = $this->makeSchool(50);
        $session  = $this->makeSession($school, '2026-09-20 11:00:00', 8000);
        $migration = require database_path('migrations/2026_09_22_000001_reset_legacy_token_usage.php');

        $migration->up();
        $migration->down();

        $this->assertSame(TokenBudget::DEFAULT_CAP, (int) $school->fresh()->setting->daily_token_cap);
        $this->assertSame(0, (int) $session->fresh()->tokens_used);
    }
}
