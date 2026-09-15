<?php

namespace App\Services;

use App\Models\PromptVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Lot 2 §6 — enregistre la version courante du prompt (version + SHA-256) si elle n'existe pas encore. */
class PromptVersionRegistrar
{
    public function register(): void
    {
        try {
            if (! Schema::hasTable('prompt_versions')) {
                return;
            }

            PromptVersion::firstOrCreate(
                ['version' => GeminiService::PROMPT_VERSION],
                [
                    'hash'         => GeminiService::systemPromptHash(),
                    'target_model' => config('services.ai.provider') === 'openai'
                        ? config('services.ai.openai_model')
                        : config('services.ai.gemini_model'),
                    'notes'        => 'Enregistré automatiquement au démarrage.',
                    'created_at'   => now(),
                ]
            );
        } catch (\Throwable $e) {
            // Jamais bloquant (base absente, migrations non jouées, tests).
            Log::debug('PromptVersionRegistrar ignoré', ['error' => $e->getMessage()]);
        }
    }
}
