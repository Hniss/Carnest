<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService implements AIService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/openai';

    private const SYSTEM_TEMPLATE = <<<'PROMPT'
Tu es Care, l'assistant IA bienveillant de CareNest pour les enfants de %d ans (groupe d'âge: %s).
Ton rôle : soutenir l'enfant avec douceur, détecter ses émotions.

RÈGLES ABSOLUES :
- Ne pose JAMAIS deux questions en même temps
- Adapte ton langage à l'âge : %s
- Ne mentionne JAMAIS que tu analyses des émotions
- Réponses courtes (2-4 phrases max)
- Réponds uniquement en français

À la fin de ta réponse, ajoute toujours sur une ligne séparée :
ZONE: green
(ou yellow, orange, red selon l'état émotionnel détecté)
PROMPT;

    private const ANALYSIS_PROMPT = <<<'PROMPT'
Analyse cette conversation et produis :
1. Un résumé bienveillant en 2-3 phrases (pour l'administrateur de l'école, jamais affiché à l'enfant)
2. La zone émotionnelle finale

Format de réponse OBLIGATOIRE :
SUMMARY: [ton résumé ici]
ZONE: green

(ZONE doit être exactement : green, yellow, orange, ou red)
PROMPT;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function chat(array $messages, int $childAge): string
    {
        $systemPrompt = $this->buildSystemPrompt($childAge);
        $response = $this->request($systemPrompt, $messages);

        $text = $response['choices'][0]['message']['content'] ?? '';
        $clean = preg_replace('/[\s,.;]*ZONE\s*:\s*(green|yellow|orange|red)\b[\s\S]*$/i', '', $text);

        return rtrim($clean, " \t\n\r,.;") . (str_ends_with(rtrim($clean), '?') || str_ends_with(rtrim($clean), '!') ? '' : '');
    }

    public function analyzeSession(array $messages, int $childAge): array
    {
        $systemPrompt = $this->buildSystemPrompt($childAge);

        $analysisMessages = array_merge($messages, [
            ['role' => 'user', 'content' => self::ANALYSIS_PROMPT],
        ]);

        $response = $this->request($systemPrompt, $analysisMessages);
        $text = $response['choices'][0]['message']['content'] ?? '';

        return $this->parseAnalysis($text);
    }

    private function buildSystemPrompt(int $age): string
    {
        $group = match (true) {
            $age <= 7  => '5-7',
            $age <= 11 => '8-11',
            default    => '12-14',
        };

        $langStyle = match ($group) {
            '5-7'   => 'très simple, émojis, phrases très courtes',
            '8-11'  => 'amical, naturel, quelques émojis',
            default => "respectueux, mature, peu d'émojis",
        };

        return sprintf(self::SYSTEM_TEMPLATE, $age, $group, $langStyle);
    }

    private const MAX_ATTEMPTS = 3;
    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    private function request(string $systemPrompt, array $messages): array
    {
        $payload = [
            'model'       => $this->model,
            'messages'    => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $messages
            ),
            'max_tokens'  => 1200,
            'temperature' => 0.7,
        ];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = $this->client()->post('/chat/completions', $payload);

            if ($response->successful()) {
                return $response->json();
            }

            $status = $response->status();
            $shouldRetry = in_array($status, self::RETRY_STATUSES, true) && $attempt < self::MAX_ATTEMPTS;

            if (! $shouldRetry) {
                Log::error('GeminiService HTTP error', [
                    'status'   => $status,
                    'attempts' => $attempt,
                    'body'     => $response->body(),
                ]);
                throw new \RuntimeException('Gemini API error: ' . $status);
            }

            usleep($attempt * 800 * 1000);
        }

        throw new \RuntimeException('Gemini API error: retries exhausted');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->apiKey)
            ->timeout(30)
            ->acceptJson();
    }

    private function parseAnalysis(string $text): array
    {
        $summary = '';
        if (preg_match('/SUMMARY:\s*(.+?)(?=\s*ZONE:|$)/si', $text, $m)) {
            $summary = trim($m[1]);
        }

        $zone = 'green';
        $lowConfidence = false;

        if (preg_match('/ZONE:\s*(green|yellow|orange|red)/i', $text, $m)) {
            $zone = strtolower($m[1]);
        } else {
            $lowConfidence = true;
        }

        if (empty($summary)) {
            $lowConfidence = true;
            $summary = 'Session sans signal émotionnel clair.';
        }

        return compact('summary', 'zone', 'lowConfidence');
    }
}
