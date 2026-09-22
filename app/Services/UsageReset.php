<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * D8 (MVP v3) — remise en cohérence des données d'usage après le changement
 * d'UNITÉ du compteur et du plafond (commit a0f956f, 21/09/2026 13:58 UTC).
 *
 * Avant ce commit, `chat_sessions.tokens_used` comptait le prompt système
 * réémis à chaque tour : ~4 000 tokens par échange. Depuis, le compteur ne
 * mesure que le contenu nouveau du tour : ~39 tokens par échange. Ni les
 * plafonds déjà enregistrés (`school_settings.daily_token_cap`), ni les
 * compteurs déjà écrits n'ont été migrés : toute installation déjà utilisée
 * reste bloquée, Care clôturant la conversation dès le premier échange.
 *
 * Deux opérations, sans effet de bord et rejouables :
 *  1. les plafonds trop bas pour la nouvelle unité repassent au défaut ;
 *  2. les compteurs antérieurs au changement d'unité repassent à zéro.
 *
 * Cette classe est le point unique de vérité, partagé par la migration de
 * données `2026_09_22_000001_reset_legacy_token_usage` et par la commande
 * `carenest:reset-usage`. Elle ne connaît que deux colonnes, jamais le schéma.
 */
class UsageReset
{
    /**
     * Horodatage du commit a0f956f (UTC, fuseau de l'application).
     * Toute session créée AVANT porte un compteur dans l'ancienne unité.
     * Ces valeurs ne sont pas convertibles : le détail des tours n'est pas
     * conservé, seul le total l'est.
     *
     * LIMITE CONNUE, assumée : la borne est l'horodatage du COMMIT, pas celui
     * du déploiement. Une installation qui a continué à tourner avec l'ancien
     * code après cette date garde, pour les sessions de cet intervalle, des
     * compteurs de l'ancienne unité que la reprise ne touche pas ; ils se
     * périment seuls à minuit (`TokenBudget::usedToday()` ne somme que le jour
     * courant). Élargir la borne à la date d'exécution remettrait à zéro des
     * compteurs légitimes déjà écrits dans la nouvelle unité : arbitrage à
     * rendre avant de changer cette constante.
     */
    public const UNIT_CHANGED_AT = '2026-09-21 13:58:54';

    /**
     * Plancher de vraisemblance d'un plafond exprimé dans la NOUVELLE unité.
     *
     * Mesure de référence (commit a0f956f) : 39 tokens par échange. La
     * conversation normale que le produit doit garantir est de 15 échanges
     * (`ChatTokenCapRealismTest::NORMAL_EXCHANGES`), soit 585 tokens, arrondi
     * à 600. En dessous, une conversation normale devient impossible : ce
     * n'est donc pas un choix de réglage, c'est une valeur héritée de
     * l'ancienne unité (où 600 tokens ne payaient même pas un seul échange).
     *
     * 0 est exclu du traitement : c'est le « plafond désactivé », un choix
     * explicite et documenté.
     */
    public const LEGACY_CAP_THRESHOLD = 600;

    /**
     * Plafonds hérités de l'ancienne unité → défaut (10 000).
     * `0` (désactivé) est préservé tel quel. Retourne le nombre d'écoles touchées.
     */
    public function resetLegacyCaps(?int $schoolId = null): int
    {
        return (int) DB::table('school_settings')
            ->when($schoolId !== null, fn ($query) => $query->where('school_id', $schoolId))
            ->where('daily_token_cap', '>', 0)
            ->where('daily_token_cap', '<', self::LEGACY_CAP_THRESHOLD)
            ->update(['daily_token_cap' => TokenBudget::DEFAULT_CAP]);
    }

    /**
     * Compteurs des sessions antérieures au changement d'unité → 0.
     * Les sessions postérieures ne sont jamais touchées.
     * Retourne le nombre de sessions touchées.
     *
     * Borne conservatrice, destinée à la migration automatique : elle ne
     * traite que ce dont on est certain. Le cas d'une installation qui a
     * continué à tourner avec l'ancien code APRÈS la date du commit est
     * couvert par `resetTodayCounters()`, déclenché à la main.
     */
    public function resetLegacyCounters(?int $schoolId = null): int
    {
        return (int) DB::table('chat_sessions')
            ->when($schoolId !== null, fn ($query) => $query->where('school_id', $schoolId))
            ->where('created_at', '<', self::UNIT_CHANGED_AT)
            ->where('tokens_used', '>', 0)
            ->update(['tokens_used' => 0]);
    }

    /**
     * Compteurs de la JOURNÉE EN COURS → 0, sans condition de date de bascule.
     *
     * Réservé au geste humain explicite (`carenest:reset-usage`), jamais joué
     * par la migration automatique. Il répond au cas réel : une installation
     * qui tournait encore avec l'ancien code après le commit a0f956f écrit des
     * compteurs de l'ancienne unité sur des sessions POSTÉRIEURES à la borne.
     * La reprise conservatrice ne les voit pas, et `TokenBudget::usedToday()`
     * les somme quand même : l'enfant reste coupé au premier échange jusqu'à
     * minuit, sans aucune issue.
     *
     * Arbitrage assumé : lancer cette commande n'arrive jamais par accident,
     * et le pire qu'elle coûte est la perte du cumul de consommation d'une
     * journée — sans commune mesure avec un testeur bloqué sans recours.
     *
     * Même fenêtre que `TokenBudget::usedToday()` (début et fin du jour dans
     * le fuseau de l'application) : on efface exactement ce que le plafond
     * additionne, ni plus, ni moins.
     */
    public function resetTodayCounters(?int $schoolId = null): int
    {
        return (int) DB::table('chat_sessions')
            ->when($schoolId !== null, fn ($query) => $query->where('school_id', $schoolId))
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->where('tokens_used', '>', 0)
            ->update(['tokens_used' => 0]);
    }
}
