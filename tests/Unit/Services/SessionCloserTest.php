<?php

namespace Tests\Unit\Services;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use App\Services\SessionCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * V5 — Clôture de session mutualisée (bouton « Fin de session » + beacon #1).
 *
 * Couvre :
 *  - clôture nominale : summary/zone persistés, alerte créée si orange/red
 *  - pire zone conservée (l'IA redescend en fin → on garde le pire observé)
 *  - idempotence : pas de 2e alerte si une existe déjà
 *  - repli zone-only si l'analyse IA échoue
 *  - clôture sans message enfant : pas d'appel IA, résumé neutre
 */
class SessionCloserTest extends TestCase
{
    use RefreshDatabase;

    private function makeChild(): Child
    {
        $school = School::factory()->create();
        return Child::factory()->for($school)->create(['age' => 10]);
    }

    private function makeOpenSession(Child $child, ?string $zone = null): ChatSession
    {
        return ChatSession::create([
            'child_id'         => $child->id,
            'school_id'        => $child->school_id,
            'started_at'       => now(),
            'last_activity_at' => now(),
            'zone'             => $zone,
        ]);
    }

    private function mockAi(array $analysis): void
    {
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('analyzeSession')->andReturn($analysis);
        $this->app->instance(AIService::class, $mock);
    }

    private function mockAiThrowing(): void
    {
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('analyzeSession')->andThrow(new \RuntimeException('Gemini 503'));
        $this->app->instance(AIService::class, $mock);
    }

    public function test_closes_session_and_persists_summary(): void
    {
        $child = $this->makeChild();
        $session = $this->makeOpenSession($child, 'green');

        $this->mockAi([
            'summary'       => 'Enfant globalement serein aujourd\'hui.',
            'zone'          => 'green',
            'alert_type'    => null,
            'lowConfidence' => false,
        ]);

        $result = app(SessionCloser::class)->close(
            $session,
            [['role' => 'user', 'content' => 'ça va bien']],
            $child,
            'green',
        );

        $session->refresh();
        $this->assertNotNull($session->ended_at);
        $this->assertSame('green', $session->zone);
        $this->assertSame('Enfant globalement serein aujourd\'hui.', $session->ai_summary);
        $this->assertFalse($result['alert_created']);
        $this->assertSame(0, Alert::count());
    }

    public function test_keeps_worst_observed_zone_and_creates_alert(): void
    {
        $child = $this->makeChild();
        $session = $this->makeOpenSession($child, 'orange');

        // L'IA redescend artificiellement à green en fin de session.
        $this->mockAi([
            'summary'       => 'Fin de session plus calme.',
            'zone'          => 'green',
            'alert_type'    => 'isolement',
            'lowConfidence' => false,
        ]);

        $result = app(SessionCloser::class)->close(
            $session,
            [['role' => 'user', 'content' => 'je suis tout seul']],
            $child,
            'orange',
            'isolement',
        );

        $session->refresh();
        // On garde la PIRE zone observée (orange), pas le green final de l'IA.
        $this->assertSame('orange', $session->zone);
        $this->assertTrue($result['alert_created']);

        $alert = Alert::where('session_id', $session->id)->first();
        $this->assertNotNull($alert);
        $this->assertSame('isolement', $alert->type);
    }

    public function test_does_not_create_second_alert_when_already_created(): void
    {
        $child = $this->makeChild();
        $session = $this->makeOpenSession($child, 'red');

        // Alerte temps réel déjà créée pendant la conversation.
        Alert::create([
            'session_id' => $session->id,
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'type'       => 'detresse',
            'level'      => 'critical',
        ]);

        $this->mockAi([
            'summary'       => 'Détresse exprimée.',
            'zone'          => 'red',
            'alert_type'    => 'detresse',
            'lowConfidence' => false,
        ]);

        app(SessionCloser::class)->close(
            $session,
            [['role' => 'user', 'content' => 'je veux disparaitre']],
            $child,
            'red',
            'detresse',
            true,
        );

        $this->assertSame(1, Alert::where('session_id', $session->id)->count());
    }

    public function test_fallback_zone_only_when_ai_fails(): void
    {
        $child = $this->makeChild();
        $session = $this->makeOpenSession($child, 'orange');

        $this->mockAiThrowing();

        $result = app(SessionCloser::class)->close(
            $session,
            [['role' => 'user', 'content' => 'je suis tout seul']],
            $child,
            'orange',
            'isolement',
        );

        $session->refresh();
        $this->assertNotNull($session->ended_at);
        $this->assertSame('orange', $session->zone);
        $this->assertTrue((bool) $session->low_confidence);
        // Même en échec d'analyse, l'alerte orange est créée.
        $this->assertTrue($result['alert_created']);
        $this->assertSame(1, Alert::where('session_id', $session->id)->count());
    }

    public function test_closes_without_ai_call_when_no_user_message(): void
    {
        $child = $this->makeChild();
        $session = $this->makeOpenSession($child, 'green');

        // L'IA ne doit JAMAIS être appelée s'il n'y a aucun message enfant.
        $mock = Mockery::mock(AIService::class);
        $mock->shouldNotReceive('analyzeSession');
        $this->app->instance(AIService::class, $mock);

        $result = app(SessionCloser::class)->close($session, [], $child, 'green');

        $session->refresh();
        $this->assertNotNull($session->ended_at);
        $this->assertTrue((bool) $session->low_confidence);
        $this->assertNotNull($session->ai_summary);
        $this->assertFalse($result['alert_created']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
