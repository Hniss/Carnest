<?php

namespace Tests\Unit\Services;

use App\Services\GeminiService;
use Tests\TestCase;

/**
 * Lot 2 §6 — empreinte des textes de prompt.
 *
 * Ce garde-fou couvre un élément CONSERVÉ (GeminiService::PROMPT_HASH). Sans lui,
 * un texte de prompt peut être modifié sans incrément de PROMPT_VERSION : les
 * sessions et les alertes seraient alors estampillées d'une version fausse.
 */
class PromptHashTest extends TestCase
{
    public function test_prompt_hash_matches_constant(): void
    {
        $this->assertSame(
            GeminiService::PROMPT_HASH,
            GeminiService::systemPromptHash(),
            'Le prompt a changé : incrémente PROMPT_VERSION et mets à jour PROMPT_HASH dans GeminiService.'
        );
    }
}
