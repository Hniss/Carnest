<?php

namespace Tests\Unit\Services;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\ChildStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P5 (Probleme CareNest V4) — tests du resolver de statut enfant à 4 paliers
 * (ok / a_surveiller / a_suivre / critique) avec override par alertes critical/high
 * non résolues dans les 7 derniers jours.
 */
class ChildStatusResolverTest extends TestCase
{
    use RefreshDatabase;

    private ChildStatusResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ChildStatusResolver();
    }

    private function makeChild(): Child
    {
        $school = School::factory()->create();
        return Child::factory()->for($school)->create(['age' => 10]);
    }

    private function makeAlert(Child $child, string $level, string $status = 'unread', int $daysAgo = 1): Alert
    {
        $session = ChatSession::create([
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'started_at' => now()->subDays($daysAgo),
            'ended_at'   => now()->subDays($daysAgo),
            'zone'       => 'orange',
        ]);

        $alert = Alert::create([
            'session_id' => $session->id,
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'type'       => 'detresse',
            'level'      => $level,
            'status'     => $status,
        ]);

        // Force la date de création (factories Eloquent ne respectent pas les timestamps custom).
        $alert->created_at = now()->subDays($daysAgo);
        $alert->save();

        return $alert;
    }

    public function test_null_score_returns_ok(): void
    {
        $child = $this->makeChild();
        $this->assertSame('ok', $this->resolver->resolve(null, $child));
    }

    public function test_high_score_returns_ok(): void
    {
        $child = $this->makeChild();
        $this->assertSame('ok', $this->resolver->resolve(85.0, $child));
    }

    public function test_mid_score_returns_a_surveiller(): void
    {
        $child = $this->makeChild();
        $this->assertSame('a_surveiller', $this->resolver->resolve(60.0, $child));
    }

    public function test_low_score_returns_a_suivre(): void
    {
        $child = $this->makeChild();
        $this->assertSame('a_suivre', $this->resolver->resolve(40.0, $child));
    }

    public function test_very_low_score_returns_critique(): void
    {
        $child = $this->makeChild();
        $this->assertSame('critique', $this->resolver->resolve(20.0, $child));
    }

    public function test_critical_alert_within_7d_overrides_high_score(): void
    {
        $child = $this->makeChild();
        $this->makeAlert($child, 'critical', daysAgo: 1);

        $this->assertSame('critique', $this->resolver->resolve(80.0, $child));
    }

    public function test_high_alert_within_7d_overrides_high_score_to_a_suivre(): void
    {
        $child = $this->makeChild();
        $this->makeAlert($child, 'high', daysAgo: 2);

        $this->assertSame('a_suivre', $this->resolver->resolve(80.0, $child));
    }

    public function test_high_alert_within_7d_keeps_a_surveiller_minimum_a_suivre(): void
    {
        $child = $this->makeChild();
        $this->makeAlert($child, 'high', daysAgo: 1);

        // Score 60 -> a_surveiller normalement, mais alerte high -> a_suivre (max).
        $this->assertSame('a_suivre', $this->resolver->resolve(60.0, $child));
    }

    public function test_critical_alert_outside_7d_does_not_override(): void
    {
        $child = $this->makeChild();
        $this->makeAlert($child, 'critical', daysAgo: 8);

        $this->assertSame('ok', $this->resolver->resolve(80.0, $child));
    }

    public function test_resolved_critical_alert_does_not_override(): void
    {
        $child = $this->makeChild();
        $this->makeAlert($child, 'critical', status: 'resolved', daysAgo: 1);

        $this->assertSame('ok', $this->resolver->resolve(80.0, $child));
    }
}
