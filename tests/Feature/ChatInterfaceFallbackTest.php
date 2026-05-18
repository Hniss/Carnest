<?php

namespace Tests\Feature;

use App\Livewire\Child\ChatInterface;
use App\Models\Child;
use App\Models\School;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Fix « boucle bot » (mai 2026) — quand l'IA échoue (Gemini 503, parse vide),
 * le composant doit :
 *   1. Ne JAMAIS répéter consécutivement la même phrase de fallback.
 *   2. Au 2e échec d'affilée, basculer sur un message dégradé honnête plutôt
 *      qu'une 2e phrase générique qui donne l'impression de boucler.
 *   3. Reset le compteur d'échecs dès que l'IA répond correctement.
 *
 * Constatation initiale : sur 2 messages enfant positifs (« je suis content »,
 * « j'ai rencontré ma prof preferee »), le bot retournait DEUX fois les phrases
 * génériques « Je t'écoute… » / « D'accord, je suis là… » — cf. capture UI.
 */
class ChatInterfaceFallbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var string[] Phrases dégradées honnêtes — au 2e échec consécutif zone green/yellow.
     * Doit rester synchrone avec ChatInterface::buildFallback().
     */
    private array $degradedMessages = [
        "Pardon, j'ai un peu de mal à te répondre là tout de suite. Tu veux bien me redire ?",
        "Désolée, ma réponse n'arrive pas comme il faut. Tu peux reformuler en une phrase courte ?",
        "Excuse-moi, j'ai un petit souci de connexion. Redis-moi en quelques mots ?",
    ];

    private function loginAsChild(): Child
    {
        $school = School::factory()->create();
        $child  = Child::factory()->for($school)->create([
            'age' => 9,
        ]);
        $this->actingAs($child, 'child');
        return $child;
    }

    private function mockAiThrowing(): void
    {
        $mock = Mockery::mock(AIService::class);
        $mock->shouldReceive('chat')
            ->andThrow(new \RuntimeException('Gemini API error: 503'));
        $this->app->instance(AIService::class, $mock);
    }

    public function test_first_ai_failure_does_not_serve_degraded_message(): void
    {
        $this->loginAsChild();
        $this->mockAiThrowing();

        $component = Livewire::test(ChatInterface::class)
            ->set('input', 'je suis content')
            ->call('sendMessage')
            ->call('fetchReply');

        $messages = $component->get('messages');
        $assistantReply = end($messages);

        $this->assertSame('assistant', $assistantReply['role']);
        $this->assertNotContains($assistantReply['content'], $this->degradedMessages,
            'Au premier échec, on ne sert PAS encore un message dégradé.');
        $this->assertSame(1, $component->get('consecutiveFailures'));
    }

    public function test_second_consecutive_failure_serves_degraded_message(): void
    {
        $this->loginAsChild();
        $this->mockAiThrowing();

        $component = Livewire::test(ChatInterface::class)
            ->set('input', 'je suis content')
            ->call('sendMessage')
            ->call('fetchReply')
            ->set('input', 'jai rencontré ma prof preferee')
            ->call('sendMessage')
            ->call('fetchReply');

        $messages = $component->get('messages');
        $secondAssistantReply = end($messages);

        $this->assertContains($secondAssistantReply['content'], $this->degradedMessages,
            'Au 2e échec d\'affilée, on doit servir un message dégradé honnête.');
        $this->assertSame(2, $component->get('consecutiveFailures'));
    }

    public function test_two_consecutive_fallbacks_are_never_identical(): void
    {
        $this->loginAsChild();
        $this->mockAiThrowing();

        $component = Livewire::test(ChatInterface::class);

        // 5 tours successifs en panne — aucune phrase ne doit apparaître 2 fois de suite.
        $assistantTexts = [];
        for ($i = 0; $i < 5; $i++) {
            $component->set('input', 'message numero ' . $i)
                ->call('sendMessage')
                ->call('fetchReply');
            $msgs = $component->get('messages');
            $assistantTexts[] = end($msgs)['content'];
        }

        for ($i = 1; $i < count($assistantTexts); $i++) {
            $this->assertNotSame(
                $assistantTexts[$i - 1],
                $assistantTexts[$i],
                "Deux fallbacks consécutifs identiques au tour {$i} — anti-boucle cassée."
            );
        }
    }

    public function test_success_resets_failure_counter(): void
    {
        $this->loginAsChild();

        // 1er tour : Gemini KO
        $mockKo = Mockery::mock(AIService::class);
        $mockKo->shouldReceive('chat')
            ->once()
            ->andThrow(new \RuntimeException('Gemini API error: 503'));
        $this->app->instance(AIService::class, $mockKo);

        $component = Livewire::test(ChatInterface::class)
            ->set('input', 'salut')
            ->call('sendMessage')
            ->call('fetchReply');

        $this->assertSame(1, $component->get('consecutiveFailures'));

        // 2e tour : Gemini OK
        $mockOk = Mockery::mock(AIService::class);
        $mockOk->shouldReceive('chat')
            ->once()
            ->andReturn([
                'message'        => 'Ça va, ravie d\'avoir de tes nouvelles. Tu veux me raconter ta journée ?',
                'zone'           => 'green',
                'alert_type'     => null,
                'is_critical'    => false,
                'low_confidence' => false,
            ]);
        $this->app->instance(AIService::class, $mockOk);

        $component->set('input', 'ça va bien')
            ->call('sendMessage')
            ->call('fetchReply');

        $this->assertSame(0, $component->get('consecutiveFailures'),
            'Le compteur d\'échecs doit revenir à 0 dès qu\'un tour aboutit.');
        $this->assertNull($component->get('lastFallback'),
            'lastFallback doit être effacé après un succès.');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
