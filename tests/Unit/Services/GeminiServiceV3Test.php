<?php

namespace Tests\Unit\Services;

use App\Services\GeminiService;
use App\Services\OpenAIService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lot 0 (MVP v3) — GeminiService : types unifiés dans le prompt, section 2511,
 * pseudonymisation (D10), version de prompt, comptage de tokens, endpoint configurable (D6).
 */
class GeminiServiceV3Test extends TestCase
{
    private function body(string $content, ?int $tokens = null, string $model = 'gemini-2.5-flash'): array
    {
        $body = ['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']], 'model' => $model];
        if ($tokens !== null) {
            $body['usage'] = ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => $tokens];
        }
        return $body;
    }

    private function service(): GeminiService
    {
        return new GeminiService('fake-key', 'gemini-2.5-flash');
    }

    /** Capture le payload envoyé au fournisseur (prompt système + messages). */
    private function captureRequests(string $content = "Je t'écoute.\nALERT_TYPE: none\nZONE: green", ?int $tokens = 42): array
    {
        $captured = [];
        Http::fake(function ($request) use (&$captured, $content, $tokens) {
            $captured[] = $request->data();
            return Http::response($this->body($content, $tokens));
        });
        return $captured;
    }

    public function test_prompt_version_constant_is_v3(): void
    {
        $this->assertStringStartsWith('v3.', GeminiService::PROMPT_VERSION);
    }

    /**
     * D8 — `chat()` compte le VOLUME DE CONVERSATION du tour : ce que le modèle
     * vient d'écrire (décompte de sortie) plus le message de l'enfant. Le prompt
     * d'entrée, qui rembarque le prompt système à chaque appel, n'est jamais compté.
     */
    public function test_chat_counts_only_the_new_content_not_the_reemitted_prompt(): void
    {
        $body = ['choices' => [['message' => ['content' => "Salut.\nALERT_TYPE: none\nZONE: green"], 'finish_reason' => 'stop']],
            'model' => 'gemini-2.5-flash',
            'usage' => ['prompt_tokens' => 3900, 'completion_tokens' => 40, 'total_tokens' => 3940]];
        Http::fake(['*' => Http::response($body)]);

        $result = $this->service()->chat([['role' => 'user', 'content' => 'bonjour']], 10);

        // 40 tokens de sortie + 'bonjour' (7 caractères → 2 tokens estimés).
        $this->assertSame(42, $result['tokens']);
        $this->assertSame('gemini-2.5-flash', $result['model']);
    }

    /** Sans `completion_tokens`, la sortie se déduit de total - entrée. */
    public function test_chat_derives_output_from_total_minus_prompt(): void
    {
        $body = ['choices' => [['message' => ['content' => "Salut.\nALERT_TYPE: none\nZONE: green"], 'finish_reason' => 'stop']],
            'model' => 'gemini-2.5-flash',
            'usage' => ['prompt_tokens' => 3900, 'total_tokens' => 3950]];
        Http::fake(['*' => Http::response($body)]);

        $result = $this->service()->chat([['role' => 'user', 'content' => 'bonjour']], 10);

        $this->assertSame(52, $result['tokens']);
    }

    /**
     * Sans aucun détail d'usage, on estime le seul texte produit + le message de
     * l'enfant — jamais `total_tokens`, qui contiendrait le prompt système.
     */
    public function test_chat_tokens_fall_back_to_an_estimate_when_usage_is_missing(): void
    {
        Http::fake(['*' => Http::response($this->body("Salut.\nALERT_TYPE: none\nZONE: green", null))]);

        $result = $this->service()->chat([['role' => 'user', 'content' => 'bonjour']], 10);

        // 35 caractères produits (9 tokens) + 'bonjour' (2 tokens).
        $this->assertSame(11, $result['tokens']);
    }

    public function test_analyze_session_returns_tokens_and_model(): void
    {
        Http::fake(['*' => Http::response($this->body("SUMMARY: Calme.\nALERT_TYPE: none\nZONE: green", 77))]);

        $result = $this->service()->analyzeSession([['role' => 'user', 'content' => 'ça va']], 10);

        $this->assertSame(77, $result['tokens']);
        $this->assertSame('gemini-2.5-flash', $result['model']);
    }

    public function test_system_prompt_uses_unified_types_and_2511_section(): void
    {
        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->data();
            return Http::response($this->body("Ok.\nALERT_TYPE: none\nZONE: green", 1));
        });

        $this->service()->chat([['role' => 'user', 'content' => 'bonjour']], 10);

        $system = $captured[0]['messages'][0]['content'];
        // Le TYPE tristesse a disparu des lignes ALERT_TYPE et des descriptions de zones.
        $this->assertStringNotContainsString('|tristesse', $system);
        $this->assertStringNotContainsString('tristesse|', $system);
        $this->assertStringNotContainsString('orange : tristesse', $system);
        $this->assertStringContainsString('pensees_negatives', $system);
        $this->assertStringContainsString('ALERT_TYPE: <none|harcelement|detresse|pensees_negatives|danger|isolement|stress|humiliation_adulte>', $system);
        $this->assertStringContainsString('USAGE DU NUMÉRO 2511', $system);
        $this->assertStringContainsString('2511', $system);
        $this->assertStringContainsString('adulte de confiance', $system);
    }

    public function test_analysis_prompt_uses_unified_types(): void
    {
        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->data();
            return Http::response($this->body("SUMMARY: x\nALERT_TYPE: none\nZONE: green", 1));
        });

        $this->service()->analyzeSession([['role' => 'user', 'content' => 'bonjour']], 10);

        $messages = $captured[0]['messages'];
        $analysisPrompt = end($messages)['content'];
        $this->assertStringNotContainsString('tristesse', $analysisPrompt);
        $this->assertStringContainsString('pensees_negatives', $analysisPrompt);
    }

    public function test_chat_accepts_pensees_negatives(): void
    {
        Http::fake(['*' => Http::response($this->body("[ALERTE_CRITIQUE]Tu n'es pas seul.\nALERT_TYPE: pensees_negatives\nZONE: red", 1))]);
        $result = $this->service()->chat([['role' => 'user', 'content' => 'x']], 12);
        $this->assertSame('pensees_negatives', $result['alert_type']);
        $this->assertSame('red', $result['zone']);
    }

    public function test_chat_rejects_legacy_tristesse_type(): void
    {
        Http::fake(['*' => Http::response($this->body("Je vois.\nALERT_TYPE: tristesse\nZONE: orange", 1))]);
        $result = $this->service()->chat([['role' => 'user', 'content' => 'x']], 12);
        $this->assertNull($result['alert_type']);
        $this->assertSame('orange', $result['zone']);
        $this->assertStringNotContainsString('tristesse', $result['message']);
    }

    public function test_age_group_12_18_in_system_prompt(): void
    {
        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->data();
            return Http::response($this->body("Ok.\nALERT_TYPE: none\nZONE: green", 1));
        });

        $this->service()->chat([['role' => 'user', 'content' => 'bonjour']], 15);

        $system = $captured[0]['messages'][0]['content'];
        $this->assertStringContainsString("groupe d'âge: 12-18", $system);
        $this->assertStringNotContainsString('12-14', $system);
    }

    public function test_base_urls_come_from_config(): void
    {
        config(['services.ai.gemini_base_url' => 'https://gemini.example.test/v1']);
        config(['services.ai.openai_base_url' => 'https://eu.api.openai.com/v1']);

        Http::fake(['*' => Http::response($this->body("Ok.\nALERT_TYPE: none\nZONE: green", 1))]);

        $this->service()->chat([['role' => 'user', 'content' => 'bonjour']], 10);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://gemini.example.test/v1/chat/completions'));

        Http::fake(['*' => Http::response($this->body("Ok.\nALERT_TYPE: none\nZONE: green", 1))]);
        (new OpenAIService('fake', 'gpt-4o-mini'))->chat([['role' => 'user', 'content' => 'bonjour']], 10);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://eu.api.openai.com/v1/chat/completions'));
    }

    public function test_config_defaults_point_to_eu_endpoints(): void
    {
        $this->assertSame('https://eu.api.openai.com/v1', config('services.ai.openai_base_url'));
        $this->assertSame('https://api.anthropic.com', config('services.ai.anthropic_base_url'));
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/openai', config('services.ai.gemini_base_url'));
    }
}
