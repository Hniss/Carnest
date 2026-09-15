<?php

namespace Tests\Feature\Child;

use App\Livewire\Child\ChatInterface;
use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use App\Services\GeminiService;
use App\Services\SessionCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * D8 (schéma) + D10 — comptage des tokens, version de prompt et modèle sur la
 * session ; summary / prompt_version / model renseignés sur l'alerte.
 */
class ChatSessionTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private function loginChild(): Child
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => 10]);
        $this->actingAs($child, 'child');
        return $child;
    }

    private function aiReply(string $zone, ?string $type, int $tokens): array
    {
        return [
            'message' => 'Je t\'écoute.', 'zone' => $zone, 'alert_type' => $type,
            'is_critical' => $zone === 'red', 'low_confidence' => false,
            'tokens' => $tokens, 'model' => 'gemini-test',
        ];
    }

    public function test_tokens_are_accumulated_and_prompt_version_model_stored(): void
    {
        $this->loginChild();
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->andReturn($this->aiReply('green', null, 30), $this->aiReply('green', null, 45));
        $this->app->instance(AIService::class, $mock);

        $component = Livewire::test(ChatInterface::class)
            ->set('input', 'salut')->call('sendMessage')->call('fetchReply')
            ->set('input', 'ça va')->call('sendMessage')->call('fetchReply');

        $session = ChatSession::find($component->get('sessionId'));
        $this->assertSame(75, $session->tokens_used);
        $this->assertSame(GeminiService::PROMPT_VERSION, $session->prompt_version);
        $this->assertSame('gemini-test', $session->model);
    }

    public function test_realtime_alert_carries_prompt_version_and_model(): void
    {
        $child = $this->loginChild();
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->andReturn($this->aiReply('orange', 'isolement', 12));
        $this->app->instance(AIService::class, $mock);

        $component = Livewire::test(ChatInterface::class)
            ->set('input', 'je suis tout seul')->call('sendMessage')->call('fetchReply');

        $alert = Alert::where('session_id', $component->get('sessionId'))->first();
        $this->assertNotNull($alert);
        $this->assertSame('isolement', $alert->type);
        $this->assertSame(GeminiService::PROMPT_VERSION, $alert->prompt_version);
        $this->assertSame('gemini-test', $alert->model);
    }

    public function test_session_closer_stores_summary_on_alert(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => 10]);
        $session = ChatSession::create([
            'child_id' => $child->id, 'school_id' => $school->id, 'started_at' => now(), 'last_activity_at' => now(),
        ]);

        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('analyzeSession')->andReturn([
            'summary' => 'Enfant isolé à la récréation.', 'zone' => 'orange', 'alert_type' => 'isolement',
            'lowConfidence' => false, 'tokens' => 200, 'model' => 'gemini-test',
        ]);
        $this->app->instance(AIService::class, $mock);

        app(SessionCloser::class)->close($session, [['role' => 'user', 'content' => 'je suis tout seul']], $child, 'orange', 'isolement');

        $alert = Alert::where('session_id', $session->id)->first();
        $this->assertSame('Enfant isolé à la récréation.', $alert->summary);
        $this->assertSame(GeminiService::PROMPT_VERSION, $alert->prompt_version);
        $this->assertSame('gemini-test', $alert->model);
        $this->assertSame(200, $session->fresh()->tokens_used);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
