<?php

namespace App\Services;

/**
 * Implémentation OpenAI de l'AIService.
 *
 * Étend GeminiService car les deux fournisseurs exposent la même API
 * Chat Completions (payload {model, messages, max_tokens, temperature},
 * Bearer auth, réponse {choices[0].message.content, finish_reason}).
 * Seuls l'URL de base et le label de log changent.
 *
 * Utilisé pour la période de test sur compte OpenAI avec solde existant.
 */
class OpenAIService extends GeminiService
{
    protected function baseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    protected function providerLabel(): string
    {
        return 'OpenAI';
    }
}
