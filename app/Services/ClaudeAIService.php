<?php

namespace App\Services;

class ClaudeAIService implements AIService
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-sonnet-4-20250514',
    ) {}

    public function chat(array $messages, int $childAge, ?string $childGender = null, ?string $childContext = null): array
    {
        throw new \RuntimeException('ClaudeAIService not implemented yet — use GeminiService for dev');
    }

    public function analyzeSession(array $messages, int $childAge, ?string $childGender = null): array
    {
        throw new \RuntimeException('ClaudeAIService not implemented yet — use GeminiService for dev');
    }
}
