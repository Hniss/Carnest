<?php

namespace App\Providers;

use App\Services\AIService;
use App\Services\ClaudeAIService;
use App\Services\GeminiService;
use App\Services\OpenAIService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

    public function boot(): void
    {
        //
    }
}
