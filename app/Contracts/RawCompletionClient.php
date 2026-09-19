<?php

namespace App\Contracts;

/**
 * Client de complétion brute (sans persona), utilisé par l'adjudicateur.
 *
 * Extrait pour que la double vérification puisse tourner sur un fournisseur dont
 * l'API n'est pas celle de GeminiService / OpenAIService (spec §6.2 : le second
 * passage utilise un modèle DIFFÉRENT du premier).
 *
 * @phpstan-type RawCompletionResult array{text:string, tokens:int, model:string}
 */
interface RawCompletionClient
{
    /**
     * @param array<int,array{role:string,content:string}> $messages Le message système éventuel y est inclus.
     * @return array{text:string, tokens:int, model:string}
     *
     * @throws \RuntimeException en cas d'échec technique.
     */
    public function rawCompletion(array $messages, float $temperature, int $maxTokens): array;
}
