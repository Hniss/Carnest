<?php

namespace App\Services;

/**
 * Implémentation OpenAI de l'AIService.
 *
 * Étend GeminiService car les deux fournisseurs exposent la même API
 * Chat Completions (payload {model, messages, max_tokens, temperature},
 * Bearer auth, réponse {choices[0].message.content, finish_reason, usage}).
 * Seuls l'URL de base et le label de log changent.
 *
 * D6 (v3) : l'URL de base est configurable (OPENAI_BASE_URL), défaut = endpoint
 * régional UE `https://eu.api.openai.com/v1` (résidence des données en Europe).
 */
class OpenAIService extends GeminiService
{
    protected function baseUrl(): string
    {
        return rtrim((string) config('services.ai.openai_base_url', 'https://eu.api.openai.com/v1'), '/');
    }

    protected function providerLabel(): string
    {
        return 'OpenAI';
    }
}
