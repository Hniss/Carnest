<?php

namespace App\Services;

use App\Models\AiCredential;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Clés d'IA : une clé enregistrée en base par le super-admin passe AVANT celle du fichier
 * de configuration du serveur ; la retirer fait revenir à celle du fichier.
 *
 * Choix automatique du fournisseur des conversations (décision du 2026-10-05, aucun écran) :
 * Gemini si sa clé existe, sinon OpenAI, sinon Claude. Si AI_PROVIDER désigne un fournisseur
 * qui a une clé et qu'aucune clé n'est en base, il est conservé (comportement antérieur).
 * La double vérification lit la même source (has()), et n'utilise jamais le même fournisseur.
 */
final class AiKeys
{
    public const PROVIDERS = ['gemini', 'openai', 'anthropic'];

    public const LABELS = ['gemini' => 'Gemini (Google)', 'openai' => 'GPT (OpenAI)', 'anthropic' => 'Claude (Anthropic)'];

    /** @return array<string, string> fournisseur => clé, pour les clés enregistrées en base. */
    private static function stored(): array
    {
        try {
            return AiCredential::query()->get(['provider', 'api_key'])
                ->filter(fn (AiCredential $c) => in_array($c->provider, self::PROVIDERS, true) && trim((string) $c->api_key) !== '')
                ->mapWithKeys(fn (AiCredential $c) => [$c->provider => trim((string) $c->api_key)])
                ->all();
        } catch (QueryException) {
            // Table absente (base pas encore migrée) : fichier du serveur seul.
            return [];
        } catch (DecryptException) {
            // Clé de l'application changée : les clés en base sont illisibles et doivent être
            // ressaisies. Repli sur le fichier du serveur, jamais de valeur journalisée.
            Log::warning('Clés d\'IA en base illisibles (clé de l\'application changée), repli sur la configuration du serveur.');

            return [];
        }
    }

    public static function key(string $provider): string
    {
        return self::stored()[$provider] ?? trim((string) config('services.ai.' . $provider . '_key'));
    }

    public static function has(string $provider): bool
    {
        return self::key($provider) !== '';
    }

    /** 'base' | 'serveur' | null */
    public static function source(string $provider): ?string
    {
        if (isset(self::stored()[$provider])) {
            return 'base';
        }

        return trim((string) config('services.ai.' . $provider . '_key')) !== '' ? 'serveur' : null;
    }

    public static function primary(): string
    {
        $stored = self::stored();
        foreach (self::PROVIDERS as $p) {
            if (isset($stored[$p])) {
                return $p;
            }
        }

        $configured = (string) config('services.ai.provider', 'gemini');
        $configured = in_array($configured, self::PROVIDERS, true) ? $configured : 'gemini';
        if (self::has($configured)) {
            return $configured;
        }
        foreach (self::PROVIDERS as $p) {
            if (self::has($p)) {
                return $p;
            }
        }

        return $configured;
    }

    public static function store(string $provider, string $key, ?int $userId): AiCredential
    {
        $key = trim($key);

        return AiCredential::updateOrCreate(
            ['provider' => $provider],
            ['api_key' => $key, 'last4' => mb_substr($key, -4), 'updated_by' => $userId],
        );
    }

    public static function remove(string $provider, ?int $userId): void
    {
        AiCredential::where('provider', $provider)->delete();
    }
}
