<?php

namespace Tests\Unit\Services;

use App\Models\PromptVersion;
use App\Services\GeminiService;
use App\Services\PromptVersionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Lot 2 §6 — versions de prompt : hash figé + enregistrement au démarrage. */
class PromptVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_hash_matches_constant(): void
    {
        $this->assertSame(
            GeminiService::PROMPT_HASH,
            GeminiService::systemPromptHash(),
            'Le prompt a changé : incrémente PROMPT_VERSION et mets à jour PROMPT_HASH dans GeminiService.'
        );
    }

    public function test_current_version_is_registered_once(): void
    {
        PromptVersion::query()->delete();

        app(PromptVersionRegistrar::class)->register();
        app(PromptVersionRegistrar::class)->register();

        $this->assertSame(1, PromptVersion::where('version', GeminiService::PROMPT_VERSION)->count());
        $row = PromptVersion::where('version', GeminiService::PROMPT_VERSION)->first();
        $this->assertSame(GeminiService::PROMPT_HASH, $row->hash);
        $this->assertSame(64, strlen($row->hash));
    }
}
