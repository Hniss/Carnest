<?php

namespace App\Providers;

use App\Contracts\RawCompletionClient;
use App\Contracts\SmsSender;
use App\Services\Adjudicator;
use App\Services\AIService;
use App\Services\ClaudeAIService;
use App\Services\FakeAIService;
use App\Services\GeminiService;
use App\Services\LogSmsSender;
use App\Services\OpenAIService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerAdjudicator();

        $this->app->singleton(AIService::class, function () {
            // Lot 3 — faux fournisseur pour la démonstration locale UNIQUEMENT
            // (APP_ENV=local ET AI_FAKE=1) ; jamais par défaut, jamais en production.
            if ($this->useFakeAi()) {
                return new FakeAIService();
            }

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
     * Lot 2 §1 — adjudicateur : SECOND fournisseur (AI_ADJUDICATOR_PROVIDER), sans persona.
     *
     * Spec §6.2 — « Ce second passage utilise un modèle différent du premier
     * (Claude Sonnet…), pour éviter qu'un biais propre à un seul modèle ne se
     * confirme lui-même. » Le fournisseur retenu ne peut donc JAMAIS être celui
     * d'AI_PROVIDER : si la configuration demande le même, ou si la clé du
     * fournisseur demandé manque, le repli est calculé dans l'ordre de préférence
     * ci-dessous et JOURNALISÉ (jamais silencieux).
     */
    private function registerAdjudicator(): void
    {
        $this->app->bind(Adjudicator::class, function () {
            if ($this->useFakeAi()) {
                return new Adjudicator(new FakeAIService());
            }

            return new Adjudicator($this->adjudicatorClient());
        });

        $this->app->bind(SmsSender::class, LogSmsSender::class);
    }

    /** Ordre de préférence du second fournisseur (spec §6.2 : Claude Sonnet d'abord). */
    private const ADJUDICATOR_PREFERENCE = ['anthropic', 'openai', 'gemini'];

    private function adjudicatorClient(): RawCompletionClient
    {
        $provider = $this->resolveAdjudicatorProvider();
        $model    = (string) config('services.ai.adjudicator_model');

        if ($provider === 'anthropic') {
            return new ClaudeAIService(
                apiKey: (string) config('services.ai.anthropic_key'),
                model: $model ?: (string) config('services.ai.anthropic_model', 'claude-sonnet-4-20250514'),
            );
        }

        if ($provider === 'openai') {
            return new OpenAIService(
                apiKey: (string) config('services.ai.openai_key'),
                model: $model ?: (string) config('services.ai.openai_model', 'gpt-4o-mini'),
            );
        }

        return new GeminiService(
            apiKey: (string) config('services.ai.gemini_key'),
            model: $model ?: (string) config('services.ai.gemini_model', 'gemini-2.5-flash'),
        );
    }

    /**
     * Fournisseur du second passage : jamais celui du premier, repli explicite et journalisé.
     */
    private function resolveAdjudicatorProvider(): string
    {
        // Un AI_PROVIDER inconnu retombe sur Gemini côté premier passage : même règle ici.
        $primary = (string) config('services.ai.provider', 'gemini');
        $primary = in_array($primary, self::ADJUDICATOR_PREFERENCE, true) ? $primary : 'gemini';
        $wanted  = (string) config('services.ai.adjudicator_provider', 'anthropic');
        $wanted  = in_array($wanted, self::ADJUDICATOR_PREFERENCE, true) ? $wanted : 'anthropic';

        $others    = array_values(array_diff(self::ADJUDICATOR_PREFERENCE, [$primary]));
        $available = array_values(array_filter($others, fn (string $p) => $this->hasKeyFor($p)));

        if ($wanted === $primary) {
            $fallback = $available[0] ?? $others[0];
            Log::warning('Adjudicateur : fournisseur identique au premier passage, repli appliqué (spec §6.2).', [
                'demande' => $wanted, 'premier_passage' => $primary, 'retenu' => $fallback,
            ]);
            $wanted = $fallback;
        }

        if (! $this->hasKeyFor($wanted)) {
            $fallback = $available[0] ?? null;

            if ($fallback === null) {
                Log::error("Adjudicateur : aucune clé configurée pour un second fournisseur — la double vérification échouera et les signaux partiront en « à confirmer ».", [
                    'demande' => $wanted, 'premier_passage' => $primary, 'cle_attendue' => strtoupper($wanted) . '_API_KEY',
                ]);

                return $wanted;
            }

            Log::warning('Adjudicateur : clé absente pour le fournisseur demandé, repli explicite appliqué.', [
                'demande' => $wanted, 'cle_attendue' => strtoupper($wanted) . '_API_KEY', 'retenu' => $fallback,
            ]);

            return $fallback;
        }

        return $wanted;
    }

    private function hasKeyFor(string $provider): bool
    {
        return trim((string) config('services.ai.' . $provider . '_key')) !== '';
    }

    /** Lot 3 — vrai seulement en environnement local avec AI_FAKE=1. */
    private function useFakeAi(): bool
    {
        return $this->app->environment('local') && filter_var(config('services.ai.fake', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function boot(): void
    {
        // Lot 3 — dates relatives (« il y a 5 minutes ») en français.
        \Illuminate\Support\Carbon::setLocale(config('app.locale', 'fr'));
    }
}
