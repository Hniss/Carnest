<?php

namespace App\Services;

use App\Contracts\RawCompletionClient;

/**
 * Client de second passage INDISPONIBLE — refus explicite.
 *
 * Spec §6.2 : « Ce second passage utilise un modèle différent du premier […] pour
 * éviter qu'un biais propre à un seul modèle ne se confirme lui-même. »
 *
 * Quand aucun second fournisseur distinct n'est réellement utilisable (aucune clé
 * configurée pour un autre fournisseur que celui du premier passage), la double
 * vérification ne peut PAS avoir lieu. Elle ne doit alors surtout pas se replier
 * sur le fournisseur du premier passage : un contrôle qui se confirme lui-même est
 * pire qu'un contrôle absent, parce qu'il a l'apparence d'une validation.
 *
 * Ce client échoue donc immédiatement, sans appel réseau. L'alerte est marquée
 * « à confirmer » par AdjudicateSignal, la session passe en confiance faible, et
 * la notification part quand même : un défaut d'outillage ne fait jamais taire un
 * signal, il le marque comme non vérifié.
 */
class UnavailableCompletionClient implements RawCompletionClient
{
    public const MESSAGE = 'Double vérification impossible : aucun second fournisseur distinct du premier passage n\'est configuré. Le signal est marqué « à confirmer » plutôt qu\'auto-validé.';

    public function rawCompletion(array $messages, float $temperature, int $maxTokens): array
    {
        throw new \RuntimeException(self::MESSAGE);
    }
}
