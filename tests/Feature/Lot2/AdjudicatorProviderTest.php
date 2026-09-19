<?php

namespace Tests\Feature\Lot2;

use App\Services\Adjudicator;
use App\Services\ClaudeAIService;
use App\Services\GeminiService;
use App\Services\OpenAIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Spec §6.2 — « Ce second passage utilise un modèle différent du premier (Claude
 * Sonnet…), pour éviter qu'un biais propre à un seul modèle ne se confirme lui-même. »
 *
 * L'adjudicateur ne peut donc JAMAIS tourner sur le fournisseur d'AI_PROVIDER, et
 * tout repli (conflit de fournisseur, clé absente) est journalisé, jamais silencieux.
 */
class AdjudicatorProviderTest extends TestCase
{
    /** Le client réellement injecté dans l'adjudicateur. */
    private function client(): object
    {
        $reflection = new \ReflectionProperty(Adjudicator::class, 'client');

        return $reflection->getValue(app(Adjudicator::class));
    }

    private function configure(string $primary, string $adjudicator, array $keys): void
    {
        config([
            'services.ai.provider'             => $primary,
            'services.ai.adjudicator_provider' => $adjudicator,
            'services.ai.adjudicator_model'    => null,
            'services.ai.gemini_key'           => $keys['gemini'] ?? '',
            'services.ai.openai_key'           => $keys['openai'] ?? '',
            'services.ai.anthropic_key'        => $keys['anthropic'] ?? '',
        ]);
        app()->forgetInstance(Adjudicator::class);
    }

    public function test_anthropic_path_exists_and_is_selected_by_default(): void
    {
        $this->configure('gemini', 'anthropic', ['gemini' => 'g', 'anthropic' => 'a']);

        $this->assertInstanceOf(ClaudeAIService::class, $this->client());
    }

    public function test_adjudicator_can_never_be_the_primary_provider(): void
    {
        Log::spy();
        $this->configure('gemini', 'gemini', ['gemini' => 'g', 'anthropic' => 'a', 'openai' => 'o']);

        $client = $this->client();
        $this->assertNotInstanceOf(GeminiService::class, $client);
        $this->assertInstanceOf(ClaudeAIService::class, $client);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_openai_primary_never_gets_an_openai_adjudicator(): void
    {
        Log::spy();
        $this->configure('openai', 'openai', ['openai' => 'o', 'anthropic' => 'a']);

        $this->assertNotInstanceOf(OpenAIService::class, $this->client());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_missing_anthropic_key_falls_back_explicitly_and_is_logged(): void
    {
        Log::spy();
        $this->configure('gemini', 'anthropic', ['gemini' => 'g', 'openai' => 'o']);

        $this->assertInstanceOf(OpenAIService::class, $this->client());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_no_second_provider_key_is_logged_as_an_error_never_silently(): void
    {
        Log::spy();
        $this->configure('gemini', 'anthropic', ['gemini' => 'g']);

        $this->assertInstanceOf(ClaudeAIService::class, $this->client());
        Log::shouldHaveReceived('error')->once();
    }

    /** Le chemin Anthropic parle bien l'API Messages : système à part, blocs de contenu. */
    public function test_anthropic_client_calls_the_messages_endpoint(): void
    {
        config(['services.ai.anthropic_base_url' => 'https://api.anthropic.com']);

        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"zone":"orange","type":"isolement","confirm":true,"signals":["sujet récurrent"]}']],
            'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
            'model'   => 'claude-sonnet-test',
        ])]);

        $result = (new Adjudicator(new ClaudeAIService('fake-key', 'claude-sonnet-test')))
            ->adjudicate([['role' => 'user', 'content' => 'personne ne veut jouer avec moi']], 'orange', 'isolement');

        $this->assertSame('confirmee', $result['verdict']);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_starts_with($request->url(), 'https://api.anthropic.com/v1/messages')
                && $request->hasHeader('anthropic-version')
                && is_string($data['system'] ?? null)
                && str_contains($data['system'], 'classificateur indépendant')
                && count($data['messages']) === 1
                && $data['messages'][0]['role'] === 'user'
                && (int) $data['temperature'] === 0;
        });
    }
}
