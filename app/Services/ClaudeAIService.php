<?php

namespace App\Services;

use App\Contracts\RawCompletionClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fournisseur Anthropic (Claude).
 *
 * Les trois méthodes de persona (`chat`, `analyzeSession`, `generateCareMemory`)
 * restent un stub : le premier passage tourne sur GeminiService / OpenAIService.
 *
 * `rawCompletion()` est en revanche implémentée : c'est le chemin utilisé par
 * l'adjudicateur (spec §6.2 — « ce second passage utilise un modèle différent du
 * premier (Claude Sonnet…), pour éviter qu'un biais propre à un seul modèle ne se
 * confirme lui-même »). L'API Messages d'Anthropic n'est pas compatible
 * Chat Completions : le message système est un champ à part et la réponse est une
 * liste de blocs de contenu.
 */
class ClaudeAIService implements AIService, RawCompletionClient
{
    private const API_VERSION = '2023-06-01';

    private const MAX_ATTEMPTS = 3;

    private const RETRY_STATUSES = [429, 500, 502, 503, 504, 529];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-sonnet-4-20250514',
    ) {}

    public function chat(array $messages, int $childAge, ?string $childGender = null, ?string $childContext = null, array $flags = []): array
    {
        throw new \RuntimeException('ClaudeAIService not implemented yet — use GeminiService for dev');
    }

    public function analyzeSession(array $messages, int $childAge, ?string $childGender = null): array
    {
        throw new \RuntimeException('ClaudeAIService not implemented yet — use GeminiService for dev');
    }

    public function generateCareMemory(array $messages, int $childAge): array
    {
        throw new \RuntimeException('ClaudeAIService not implemented yet — use GeminiService for dev');
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @return array{text:string, tokens:int, model:string}
     */
    public function rawCompletion(array $messages, float $temperature, int $maxTokens): array
    {
        [$system, $turns] = $this->split($messages);

        $payload = [
            'model'       => $this->model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'messages'    => $turns,
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }

        $response = $this->send($payload);

        return [
            'text'   => $this->firstText($response),
            'tokens' => (int) ($response['usage']['input_tokens'] ?? 0) + (int) ($response['usage']['output_tokens'] ?? 0),
            'model'  => is_string($response['model'] ?? null) && $response['model'] !== '' ? $response['model'] : $this->model,
        ];
    }

    /**
     * Sépare le message système (champ dédié chez Anthropic) des tours de conversation.
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @return array{0:string, 1:array<int,array{role:string,content:string}>}
     */
    private function split(array $messages): array
    {
        $system = [];
        $turns  = [];

        foreach ($messages as $m) {
            $role    = (string) ($m['role'] ?? 'user');
            $content = (string) ($m['content'] ?? '');

            if ($role === 'system') {
                $system[] = $content;
                continue;
            }

            $turns[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $content];
        }

        return [implode("\n\n", $system), $turns];
    }

    /** @param array<string,mixed> $response */
    private function firstText(array $response): string
    {
        foreach ((array) ($response['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function send(array $payload): array
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = $this->client()->post('/v1/messages', $payload);

            if ($response->successful()) {
                return $response->json();
            }

            $status = $response->status();
            if (! in_array($status, self::RETRY_STATUSES, true) || $attempt >= self::MAX_ATTEMPTS) {
                Log::error('AnthropicService HTTP error', ['status' => $status, 'attempts' => $attempt]);
                throw new \RuntimeException('Anthropic API error: ' . $status);
            }

            usleep($attempt * 800 * 1000);
        }

        throw new \RuntimeException('Anthropic API error: retries exhausted');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.ai.anthropic_base_url', 'https://api.anthropic.com'), '/'))
            ->withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ])
            ->timeout(30)
            ->acceptJson();
    }
}
