<?php

namespace Tests\Feature\Child;

use App\Livewire\Child\ChatInterface;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * #7 (Probleme CareNest V5) — Mémoire inter-sessions, vue côté composant.
 *
 * Couvre :
 *  - welcome personnalisé par prénom + variante « de te revoir » pour un récurrent
 *  - childContext null au 1er passage, non-null avec historique
 *  - le bloc mémoire est bien transmis à AIService::chat()
 *  - #3 : réponse courte « rien » → relance neutre, jamais d'escalade
 */
class ChatInterfaceMemoryTest extends TestCase
{
    use RefreshDatabase;

    private function loginChild(array $attrs = []): Child
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(array_merge([
            'name'   => 'Yassine Test',
            'age'    => 10,
            'classe' => 'CM1',
        ], $attrs));
        $this->actingAs($child, 'child');
        return $child;
    }

    private function makeClosedSession(Child $child, string $zone, CarbonImmutable $endedAt, ?string $summary = null): void
    {
        $session = ChatSession::create([
            'child_id'   => $child->id,
            'school_id'  => $child->school_id,
            'started_at' => $endedAt->subMinutes(10),
            'ended_at'   => $endedAt,
            'zone'       => $zone,
            'ai_summary' => $summary,
        ]);
        $session->ended_at = $endedAt;
        $session->save();
    }

    public function test_first_session_has_null_context_and_plain_welcome(): void
    {
        $this->loginChild();

        $component = Livewire::test(ChatInterface::class);

        $this->assertNull($component->get('childContext'));
        $messages = $component->get('messages');
        $this->assertStringContainsString('Yassine', $messages[0]['content']);
        // 1er passage → pas la variante « de te revoir ».
        $this->assertStringNotContainsString('revoir', $messages[0]['content']);
    }

    public function test_returning_child_gets_context_and_welcome_back(): void
    {
        $child = $this->loginChild();
        $this->makeClosedSession($child, 'orange', CarbonImmutable::now()->subDays(2), 'Enfant un peu isolé.');

        $component = Livewire::test(ChatInterface::class);

        $this->assertNotNull($component->get('childContext'));
        $messages = $component->get('messages');
        $this->assertStringContainsString('Yassine', $messages[0]['content']);
        $this->assertStringContainsString('revoir', $messages[0]['content']);
    }

    public function test_context_is_passed_to_ai_chat(): void
    {
        $child = $this->loginChild();
        $this->makeClosedSession($child, 'orange', CarbonImmutable::now()->subDays(2), 'Enfant un peu isolé.');

        $captured = ['called' => false, 'context' => null];
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->andReturnUsing(
            function ($messages, $age, $gender, $context = null) use (&$captured) {
                $captured['called'] = true;
                $captured['context'] = $context;
                return [
                    'message'        => 'Contente de te retrouver. Comment ça va ?',
                    'zone'           => 'green',
                    'alert_type'     => null,
                    'is_critical'    => false,
                    'low_confidence' => false,
                ];
            }
        );
        $this->app->instance(AIService::class, $mock);

        Livewire::test(ChatInterface::class)
            ->set('input', 'salut')
            ->call('sendMessage')
            ->call('fetchReply');

        $this->assertTrue($captured['called']);
        $this->assertIsString($captured['context']);
        $this->assertStringContainsString('MÉMOIRE', $captured['context']);
    }

    public function test_short_ambiguous_answer_does_not_escalate_on_ai_failure(): void
    {
        $this->loginChild();

        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')->andThrow(new \RuntimeException('Gemini 503'));
        $this->app->instance(AIService::class, $mock);

        $component = Livewire::test(ChatInterface::class)
            ->set('input', 'rien')
            ->call('sendMessage')
            ->call('fetchReply');

        $this->assertSame('green', $component->get('currentZone'));
        $messages = $component->get('messages');
        $reply = end($messages);
        // Relance neutre à choix simples — pas de ton de détresse.
        $this->assertStringContainsStringIgnoringCase('préfère', $reply['content']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
