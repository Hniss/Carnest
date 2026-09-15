<?php

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Services\Adjudicator;
use App\Services\AIService;
use App\Services\ClaudeAIService;
use App\Services\GeminiService;
use App\Services\LogSmsSender;
use App\Services\PromptVersionRegistrar;
use App\Services\OpenAIService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerAdjudicator();

        $this->app->singleton(AIService::class, function () {
            $provider = config('services.ai.provider', 'gemini');

            if ($provider === 'anthropic') {
                return new ClaudeAIService(
                    apiKey: config('services.ai.anthropic_key'),
                    model: config('services.ai.anthropic_model', 'claude-sonnet-4-20250514'),
                );
            }

            if ($provider === 'openai') {
                return new OpenAIService(
                    apiKey: config('services.ai.openai_key'),
                    model: config('services.ai.openai_model', 'gpt-4o-mini'),
                );
            }

            return new GeminiService(
                apiKey: config('services.ai.gemini_key'),
                model: config('services.ai.gemini_model', 'gemini-2.5-flash'),
            );
        });
    }

    /**
     * Lot 2 §1 — adjudicateur : SECOND fournisseur (AI_ADJUDICATOR_PROVIDER, défaut gemini),
     * sans persona. Réutilise le client HTTP de GeminiService / OpenAIService.
     */
    private function registerAdjudicator(): void
    {
        $this->app->bind(Adjudicator::class, function () {
            $provider = config('services.ai.adjudicator_provider', 'gemini');

            $client = $provider === 'openai'
                ? new OpenAIService(
                    apiKey: (string) config('services.ai.openai_key'),
                    model: (string) (config('services.ai.adjudicator_model') ?: config('services.ai.openai_model', 'gpt-4o-mini')),
                )
                : new GeminiService(
                    apiKey: (string) config('services.ai.gemini_key'),
                    model: (string) (config('services.ai.adjudicator_model') ?: config('services.ai.gemini_model', 'gemini-2.5-flash')),
                );

            return new Adjudicator($client);
        });

        $this->app->bind(SmsSender::class, LogSmsSender::class);
    }

    public function boot(): void
    {
        // Lot 2 §6 — enregistre la version courante du prompt (jamais bloquant).
        if (! $this->app->runningUnitTests()) {
            $this->app->make(PromptVersionRegistrar::class)->register();
        }
    }
}
