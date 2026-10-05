<?php

namespace App\Providers;

use App\Contracts\RawCompletionClient;
use App\Contracts\SmsSender;
use App\Services\Adjudicator;
use App\Services\AIService;
use App\Services\AiKeys;
use App\Services\ClaudeAIService;
use App\Services\FakeAIService;
use App\Services\GeminiService;
use App\Services\LogSmsSender;
use App\Services\OpenAIService;
use App\Services\UnavailableCompletionClient;
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

            // Choix automatique : clés en base d'abord (espace super-admin), puis fichier du serveur.
            $provider = AiKeys::primary();

            if ($provider === 'anthropic') {
                return new ClaudeAIService(
                    apiKey: AiKeys::key('anthropic'),
                    model: config('services.ai.anthropic_model', 'claude-sonnet-4-20250514'),
                );
            }

            if ($provider === 'openai') {
                return new OpenAIService(
                    apiKey: AiKeys::key('openai'),
                    model: config('services.ai.openai_model', 'gpt-4o-mini'),
                );
            }

            return new GeminiService(
                apiKey: AiKeys::key('gemini'),
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

        // Aucun second fournisseur distinct réellement utilisable : on REFUSE
        // explicitement de faire tourner le contrôle sur le fournisseur du premier
        // passage, qui se confirmerait lui-même (spec §6.2).
        if ($provider === null) {
            return new UnavailableCompletionClient();
        }

        if ($provider === 'anthropic') {
            return new ClaudeAIService(
                apiKey: AiKeys::key('anthropic'),
                model: $model ?: (string) config('services.ai.anthropic_model', 'claude-sonnet-4-20250514'),
            );
        }

        if ($provider === 'openai') {
            return new OpenAIService(
                apiKey: AiKeys::key('openai'),
                model: $model ?: (string) config('services.ai.openai_model', 'gpt-4o-mini'),
            );
        }

        return new GeminiService(
            apiKey: AiKeys::key('gemini'),
            model: $model ?: (string) config('services.ai.gemini_model', 'gemini-2.5-flash'),
        );
    }

    /**
     * Fournisseur du second passage : jamais celui du premier, repli explicite et journalisé.
     *
     * Rend `null` quand aucun fournisseur distinct du premier passage n'a de clé :
     * dans ce cas la double vérification est déclarée indisponible (elle échouera et
     * l'alerte sera marquée « à confirmer »). Elle ne retombe JAMAIS sur le
     * fournisseur du premier passage, ni sur un fournisseur sans clé dont l'appel
     * partirait quand même sur le réseau.
     */
    private function resolveAdjudicatorProvider(): ?string
    {
        // Un AI_PROVIDER inconnu retombe sur Gemini côté premier passage : même règle ici.
        // Même source que le chat : fournisseur choisi automatiquement (base puis fichier serveur).
        $primary = AiKeys::primary();
        $wanted  = (string) config('services.ai.adjudicator_provider', 'anthropic');
        $wanted  = in_array($wanted, self::ADJUDICATOR_PREFERENCE, true) ? $wanted : 'anthropic';

        $others    = array_values(array_diff(self::ADJUDICATOR_PREFERENCE, [$primary]));
        $available = array_values(array_filter($others, fn (string $p) => $this->hasKeyFor($p)));

        if ($wanted === $primary) {
            $fallback = $available[0] ?? null;
            Log::warning('Adjudicateur : fournisseur identique au premier passage, repli appliqué (spec §6.2).', [
                'demande' => $wanted, 'premier_passage' => $primary, 'retenu' => $fallback ?? 'aucun',
            ]);
            $wanted = $fallback;
        }

        if ($wanted === null || ! $this->hasKeyFor($wanted)) {
            $fallback = $available[0] ?? null;

            if ($fallback === null) {
                Log::error("Adjudicateur : aucune clé configurée pour un second fournisseur — la double vérification est déclarée indisponible et les signaux partiront en « à confirmer », jamais auto-validés par le fournisseur du premier passage.", [
                    'demande' => $wanted ?? 'aucun', 'premier_passage' => $primary,
                    'cle_attendue' => $wanted !== null ? strtoupper($wanted) . '_API_KEY' : null,
                ]);

                return null;
            }

            Log::warning('Adjudicateur : clé absente pour le fournisseur demandé, repli explicite appliqué.', [
                'demande' => $wanted, 'cle_attendue' => strtoupper($wanted) . '_API_KEY', 'retenu' => $fallback,
            ]);

            return $fallback;
        }

        // Garde-fou final : quoi qu'il arrive, jamais le fournisseur du premier passage.
        if ($wanted === $primary) {
            Log::error('Adjudicateur : le repli aboutissait au fournisseur du premier passage — refusé (spec §6.2).', [
                'premier_passage' => $primary,
            ]);

            return null;
        }

        return $wanted;
    }

    private function hasKeyFor(string $provider): bool
    {
        return AiKeys::has($provider);
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

        // Boîte d'envoi réglée dans l'espace super-admin : appliquée avant le premier e-mail
        // (alertes, liens de mot de passe). Sans réglage en base : fichier du serveur.
        $this->app->afterResolving('mail.manager', fn () => \App\Services\MailSettings::apply());

        // Journal de l'espace super-admin (connexion, déconnexion, tentative échouée) :
        // App\Listeners\JournalSuperAdminAuth, enregistré par la découverte automatique des écouteurs.
    }
}
