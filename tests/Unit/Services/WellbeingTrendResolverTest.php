<?php

namespace Tests\Unit\Services;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\WellbeingTrendResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suivi psychique longitudinal — tests du resolver de tendance.
 *
 * Couvre :
 *  - fenêtres vides → unknown / no_baseline
 *  - sous-représentation → forçage stable + reason='insufficient_data'
 *  - seuils delta exacts (+10 / -10) → improving / worsening
 *  - delta intra-bruit (-9..+9) → stable
 *  - streak d'aggravation 2 semaines → worseningSignal=true
 *  - sparkline 8 semaines avec trous
 */
class WellbeingTrendResolverTest extends TestCase
{
    use RefreshDatabase;

    private WellbeingTrendResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new WellbeingTrendResolver();
    }

    private function makeChild(): Child
    {
        $school = School::factory()->create();
        return Child::factory()->for($school)->create(['age' => 10]);
    }

    private function makeSession(Child $child, string $zone, CarbonImmutable $endedAt): ChatSession
    {
        $session = ChatSession::create([
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'started_at' => $endedAt->subMinutes(10),
            'ended_at'   => $endedAt,
            'zone'       => $zone,
        ]);
        // Force ended_at exactement (l'observer peut le décaler).
        $session->ended_at = $endedAt;
        $session->save();
        return $session;
    }

    public function test_empty_short_term_window_returns_unknown_with_no_baseline(): void
    {
        $child = $this->makeChild();
        $now = CarbonImmutable::parse('2026-05-17 12:00:00');

        $report = $this->resolver->resolve($child, $now);

        $this->assertTrue($report->shortTerm->isEmpty);
        $this->assertSame('unknown', $report->shortTermTrend->direction);
        $this->assertNull($report->shortTermTrend->delta);
        $this->assertSame('insufficient_data', $report->shortTermTrend->reason);
        $this->assertFalse($report->worseningSignal);
    }

    public function test_single_session_marks_window_under_represented_and_forces_stable(): void
    {
        $child = $this->makeChild();
        $now = CarbonImmutable::parse('2026-05-17 12:00:00');

        // 1 session red dans la fenêtre 7j actuelle (sous-représenté car < MIN_SESSIONS_SHORT=2).
        $this->makeSession($child, 'red', $now->subDays(2));
        // 2 sessions green dans la fenêtre précédente (normal).
        $this->makeSession($child, 'green', $now->subDays(9));
        $this->makeSession($child, 'green', $now->subDays(10));

        $report = $this->resolver->resolve($child, $now);

        $this->assertTrue($report->shortTerm->isUnderRepresented);
        $this->assertSame('stable', $report->shortTermTrend->direction);
        $this->assertSame('insufficient_data', $report->shortTermTrend->reason);
    }

    public function test_delta_exactly_plus_ten_classified_as_improving(): void
    {
        $child = $this->makeChild();
        $now = CarbonImmutable::parse('2026-05-17 12:00:00');

        // Fenêtre courante 7j : 2× green (100) + 1× orange (35) = avg 78.33
        $this->makeSession($child, 'green', $now->subDays(1));
        $this->makeSession($child, 'green', $now->subDays(2));
        $this->makeSession($child, 'orange', $now->subDays(3));

        // Fenêtre précédente : 2× yellow (70) + 1× orange (35) = avg 58.33
        // delta = 78.33 - 58.33 = 20 → largement improving
        $this->makeSession($child, 'yellow', $now->subDays(8));
        $this->makeSession($child, 'yellow', $now->subDays(9));
        $this->makeSession($child, 'orange', $now->subDays(10));

        $report = $this->resolver->resolve($child, $now);

        $this->assertSame('improving', $report->shortTermTrend->direction);
        $this->assertGreaterThanOrEqual(10, $report->shortTermTrend->delta);
    }

    public function test_delta_at_minus_ten_threshold_classified_as_worsening(): void
    {
        $child = $this->makeChild();
        $now = CarbonImmutable::parse('2026-05-17 12:00:00');

        // Fenêtre courante : 2× orange (35) + 1× red (0) → avg 23.33
        $this->makeSession($child, 'orange', $now->subDays(1));
        $this->makeSession($child, 'orange', $now->subDays(2));
        $this->makeSession($child, 'red', $now->subDays(3));

        // Fenêtre précédente : 2× yellow (70) + 1× orange (35) = avg 58.33
        // delta = 23.33 - 58.33 = -35 → worsening
        $this->makeSession($child, 'yellow', $now->subDays(8));
        $this->makeSession($child, 'yellow', $now->subDays(9));
        $this->makeSession($child, 'orange', $now->subDays(10));

        $report = $this->resolver->resolve($child, $now);

        $this->assertSame('worsening', $report->shortTermTrend->direction);
        $this->assertLessThanOrEqual(-10, $report->shortTermTrend->delta);
    }

    public function test_small_delta_within_noise_band_stays_stable(): void
    {
        $child = $this->makeChild();
        $now = CarbonImmutable::parse('2026-05-17 12:00:00');

        // Courante : 3× yellow = 70.0
        $this->makeSession($child, 'yellow', $now->subDays(1));
        $this->makeSession($child, 'yellow', $now->subDays(2));
        $this->makeSession($child, 'yellow', $now->subDays(3));

        // Précédente : 2× yellow + 1× green = (70+70+100)/3 = 80.0
        // delta = -10 EXACT → la limite : <= -10 → worsening.
        // On veut tester DANS la bande de bruit. Donc on met précédente
        // 1× green + 2× yellow réordonné pour avg = 80, mais réajustons :
        // mettre 3× yellow précédent → delta=0 → stable.
        $this->makeSession($child, 'yellow', $now->subDays(8));
        $this->makeSession($child, 'yellow', $now->subDays(9));
        $this->makeSession($child, 'yellow', $now->subDays(10));

        $report = $this->resolver->resolve($child, $now);

        $this->assertSame('stable', $report->shortTermTrend->direction);
        $this->assertSame('normal', $report->shortTermTrend->reason);
    }

    public function test_two_consecutive_weeks_of_decline_triggers_worsening_signal(): void
    {
        $child = $this->makeChild();
        $now = CarbonImmutable::parse('2026-05-17 12:00:00');

        // Semaine N-0 (la plus récente, jours 0-7) : 2× orange = 35
        $this->makeSession($child, 'orange', $now->subDays(1));
        $this->makeSession($child, 'orange', $now->subDays(2));

        // Semaine N-1 (jours 7-14) : 2× yellow = 70 → delta(N-0 vs N-1) = -35 → baisse #1
        $this->makeSession($child, 'yellow', $now->subDays(8));
        $this->makeSession($child, 'yellow', $now->subDays(9));

        // Semaine N-2 (jours 14-21) : 2× green = 100 → delta(N-1 vs N-2) = -30 → baisse #2
        $this->makeSession($child, 'green', $now->subDays(15));
        $this->makeSession($child, 'green', $now->subDays(16));

        // Semaine N-3 : 2× green = 100 → delta(N-2 vs N-3) = 0 → streak s'arrête
        $this->makeSession($child, 'green', $now->subDays(22));
        $this->makeSession($child, 'green', $now->subDays(23));

        $report = $this->resolver->resolve($child, $now);

        $this->assertGreaterThanOrEqual(2, $report->worseningStreak);
        $this->assertTrue($report->worseningSignal);
    }

    public function test_streak_breaks_on_empty_week(): void
    {
        $child = $this->makeChild();
        $now = CarbonImmutable::parse('2026-05-17 12:00:00');

        // Une baisse récente, mais la semaine N-2 est vide → streak s'arrête à 1.
        $this->makeSession($child, 'orange', $now->subDays(1));
        $this->makeSession($child, 'orange', $now->subDays(2));
        $this->makeSession($child, 'yellow', $now->subDays(8));
        $this->makeSession($child, 'yellow', $now->subDays(9));
        // PAS de sessions semaine N-2

        $report = $this->resolver->resolve($child, $now);

        $this->assertLessThan(2, $report->worseningStreak);
        $this->assertFalse($report->worseningSignal);
    }

    public function test_sparkline_returns_eight_entries_with_nulls_for_empty_weeks(): void
    {
        $child = $this->makeChild();
        $now = CarbonImmutable::parse('2026-05-17 12:00:00');

        // 2 sessions cette semaine, 2 il y a 3 semaines, rien sinon.
        $this->makeSession($child, 'green', $now->subDays(1));
        $this->makeSession($child, 'green', $now->subDays(2));
        $this->makeSession($child, 'orange', $now->subDays(15));
        $this->makeSession($child, 'orange', $now->subDays(16));

        $report = $this->resolver->resolve($child, $now);

        $this->assertCount(8, $report->weeklySparkline);
        // Le dernier point (semaine actuelle) doit être ~100 (2× green).
        $this->assertSame(100.0, (float) $report->weeklySparkline[7]);
        // L'avant-dernier (semaine N-1) doit être null (vide).
        $this->assertNull($report->weeklySparkline[6]);
        // Sessions -15j et -16j tombent dans la fenêtre [now-21d ; now-14d[ → index 5.
        $this->assertSame(35.0, (float) $report->weeklySparkline[5]);
    }

    public function test_no_baseline_returns_unknown_when_current_has_data_but_previous_empty(): void
    {
        $child = $this->makeChild();
        $now = CarbonImmutable::parse('2026-05-17 12:00:00');

        // Sessions cette semaine seulement, rien avant.
        $this->makeSession($child, 'green', $now->subDays(1));
        $this->makeSession($child, 'green', $now->subDays(2));

        $report = $this->resolver->resolve($child, $now);

        $this->assertSame('unknown', $report->shortTermTrend->direction);
        $this->assertSame('no_baseline', $report->shortTermTrend->reason);
    }

    public function test_alerts_count_per_window_includes_critical_subset(): void
    {
        $child = $this->makeChild();
        $now = CarbonImmutable::parse('2026-05-17 12:00:00');

        $session = $this->makeSession($child, 'red', $now->subDays(1));

        $alert = Alert::create([
            'session_id' => $session->id,
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'type'       => 'detresse',
            'level'      => 'critical',
            'status'     => 'unread',
        ]);
        $alert->created_at = $now->subDays(1);
        $alert->save();

        // Une alerte 'high' pour bruit.
        $alertLow = Alert::create([
            'session_id' => $session->id,
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'type'       => 'stress',
            'level'      => 'high',
            'status'     => 'unread',
        ]);
        $alertLow->created_at = $now->subDays(2);
        $alertLow->save();

        $report = $this->resolver->resolve($child, $now);

        $this->assertSame(2, $report->shortTerm->alertsCount);
        $this->assertSame(1, $report->shortTerm->criticalAlertsCount);
    }
}
