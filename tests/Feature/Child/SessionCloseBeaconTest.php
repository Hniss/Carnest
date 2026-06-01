<?php

namespace Tests\Feature\Child;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * #1 (Probleme CareNest V5) — Beacon de clôture (fermeture/actualisation de fenêtre).
 *
 * Couvre :
 *  - clôture de la session de l'enfant connecté (résumé + zone persistés)
 *  - refus si la session appartient à un autre enfant (403)
 *  - aucune fuite de message brut en BDD (seul le résumé IA est stocké)
 */
class SessionCloseBeaconTest extends TestCase
{
    use RefreshDatabase;

    private function makeChild(School $school): Child
    {
        return Child::factory()->for($school)->create(['age' => 10]);
    }

    private function mockAi(): void
    {
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('analyzeSession')->andReturn([
            'summary'       => 'Résumé bienveillant de la session.',
            'zone'          => 'orange',
            'alert_type'    => 'isolement',
            'lowConfidence' => false,
        ]);
        $this->app->instance(AIService::class, $mock);
    }

    public function test_beacon_closes_own_session(): void
    {
        $this->mockAi();
        $school = School::factory()->create();
        $child = $this->makeChild($school);
        $this->actingAs($child, 'child');

        $session = ChatSession::create([
            'child_id'         => $child->id,
            'school_id'        => $child->school_id,
            'started_at'       => now(),
            'last_activity_at' => now(),
            'zone'             => 'orange',
        ]);

        $response = $this->postJson(route('child.chat.close'), [
            'session_id' => $session->id,
            'messages'   => [
                ['role' => 'assistant', 'content' => 'Salut !'],
                ['role' => 'user', 'content' => 'je suis tout seul a la recre'],
            ],
        ]);

        $response->assertNoContent();

        $session->refresh();
        $this->assertNotNull($session->ended_at);
        $this->assertSame('orange', $session->zone);
        $this->assertSame('Résumé bienveillant de la session.', $session->ai_summary);
        $this->assertSame(1, Alert::where('session_id', $session->id)->count());
    }

    public function test_beacon_rejects_other_childs_session(): void
    {
        $this->mockAi();
        $school = School::factory()->create();
        $owner = $this->makeChild($school);
        $attacker = $this->makeChild($school);

        $session = ChatSession::create([
            'child_id'         => $owner->id,
            'school_id'        => $owner->school_id,
            'started_at'       => now(),
            'last_activity_at' => now(),
            'zone'             => 'red',
        ]);

        $this->actingAs($attacker, 'child');

        $response = $this->postJson(route('child.chat.close'), [
            'session_id' => $session->id,
            'messages'   => [['role' => 'user', 'content' => 'test']],
        ]);

        $response->assertForbidden();

        $session->refresh();
        $this->assertNull($session->ended_at, 'La session d\'un autre enfant ne doit pas être clôturée.');
    }

    public function test_beacon_does_not_store_raw_messages(): void
    {
        $this->mockAi();
        $school = School::factory()->create();
        $child = $this->makeChild($school);
        $this->actingAs($child, 'child');

        $session = ChatSession::create([
            'child_id'         => $child->id,
            'school_id'        => $child->school_id,
            'started_at'       => now(),
            'last_activity_at' => now(),
            'zone'             => 'orange',
        ]);

        $secret = 'mon-secret-brut-unique-12345';
        $this->postJson(route('child.chat.close'), [
            'session_id' => $session->id,
            'messages'   => [['role' => 'user', 'content' => $secret]],
        ])->assertNoContent();

        $session->refresh();
        // Le message brut ne doit apparaître nulle part dans la session persistée.
        $this->assertStringNotContainsString($secret, (string) $session->ai_summary);
        $this->assertStringNotContainsString($secret, json_encode($session->getAttributes()));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
