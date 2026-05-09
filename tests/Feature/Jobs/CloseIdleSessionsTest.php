<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CloseIdleSessions;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2 (Probleme CareNest V4) — tests du job CloseIdleSessions exécuté toutes
 * les 2 minutes par le scheduler. Doit fermer proprement les sessions
 * inactives depuis ≥ 5 minutes, créer une alerte si nécessaire (idempotent),
 * et déclencher le recalcul du score climat.
 */
class CloseIdleSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeChild(): Child
    {
        $school = School::factory()->create();
        return Child::factory()->for($school)->create(['age' => 10]);
    }

    private function makeSession(Child $child, ?string $zone, int $minutesIdle, ?\Carbon\Carbon $endedAt = null): ChatSession
    {
        return ChatSession::create([
            'child_id'         => $child->id,
            'school_id'        => $child->school_id,
            'started_at'       => now()->subMinutes($minutesIdle + 5),
            'ended_at'         => $endedAt,
            'zone'             => $zone,
            'last_activity_at' => now()->subMinutes($minutesIdle),
        ]);
    }

    public function test_idle_session_over_5min_is_closed(): void
    {
        $child = $this->makeChild();
        $session = $this->makeSession($child, 'green', 6);

        (new CloseIdleSessions())->handle();

        $session->refresh();
        $this->assertNotNull($session->ended_at);
        $this->assertNotNull($session->ai_summary);
    }

    public function test_idle_session_under_5min_stays_active(): void
    {
        $child = $this->makeChild();
        $session = $this->makeSession($child, 'green', 3);

        (new CloseIdleSessions())->handle();

        $session->refresh();
        $this->assertNull($session->ended_at);
    }

    public function test_already_ended_session_is_not_touched(): void
    {
        $child = $this->makeChild();
        $endedAt = now()->subMinutes(20);
        $session = $this->makeSession($child, 'green', 30, $endedAt);

        (new CloseIdleSessions())->handle();

        $session->refresh();
        // ended_at ne doit pas avoir bougé.
        $this->assertEquals($endedAt->timestamp, $session->ended_at->timestamp);
    }

    public function test_orange_idle_session_creates_moderate_alert(): void
    {
        $child = $this->makeChild();
        $session = $this->makeSession($child, 'orange', 7);

        (new CloseIdleSessions())->handle();

        $alert = Alert::where('session_id', $session->id)->first();
        $this->assertNotNull($alert);
        $this->assertSame('moderate', $alert->level);
    }

    public function test_red_idle_session_creates_critical_alert(): void
    {
        $child = $this->makeChild();
        $session = $this->makeSession($child, 'red', 10);

        (new CloseIdleSessions())->handle();

        $alert = Alert::where('session_id', $session->id)->first();
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->level);
    }

    public function test_green_idle_session_does_not_create_alert(): void
    {
        $child = $this->makeChild();
        $session = $this->makeSession($child, 'green', 7);

        (new CloseIdleSessions())->handle();

        $this->assertSame(0, Alert::where('session_id', $session->id)->count());
        $session->refresh();
        $this->assertNotNull($session->ended_at);
    }

    public function test_null_zone_idle_session_is_low_confidence_and_no_alert(): void
    {
        $child = $this->makeChild();
        $session = $this->makeSession($child, null, 7);

        (new CloseIdleSessions())->handle();

        $session->refresh();
        $this->assertSame('green', $session->zone);
        $this->assertTrue((bool) $session->low_confidence);
        $this->assertSame(0, Alert::where('session_id', $session->id)->count());
    }

    public function test_existing_alert_is_not_duplicated(): void
    {
        $child = $this->makeChild();
        $session = $this->makeSession($child, 'orange', 7);

        // Alerte préexistante (créée pendant la session par le temps réel).
        Alert::create([
            'session_id' => $session->id,
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'type'       => 'harcelement',
            'level'      => 'high',
        ]);

        (new CloseIdleSessions())->handle();

        $alerts = Alert::where('session_id', $session->id)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame('high', $alerts->first()->level);
    }

    public function test_close_recalculates_child_score_via_process_session_closure(): void
    {
        $child = $this->makeChild();
        $session = $this->makeSession($child, 'orange', 6);

        (new CloseIdleSessions())->handle();

        $child->refresh();
        // Une session orange (35 pts) sur 7 jours -> score 35.
        $this->assertNotNull($child->score_enfant);
        $this->assertEquals(35.0, (float) $child->score_enfant);
        $this->assertNotNull($child->last_session_at);
    }
}
