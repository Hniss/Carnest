<?php

use App\Services\UsageReset;
use Illuminate\Database\Migrations\Migration;

/**
 * D8 (MVP v3) — migration de DONNÉES (aucune modification de schéma).
 *
 * Le commit a0f956f a changé l'UNITÉ du compteur de tokens et, avec elle, le
 * sens du plafond journalier — sans migrer la moindre donnée existante. Toute
 * installation déjà utilisée reste cassée : Care clôture la conversation dès
 * le premier échange. Deux causes cumulées, traitées ici :
 *
 *  1. `school_settings.daily_token_cap` a pu être abaissé à une valeur de
 *     l'ancienne unité (le protocole de test du README demandait 50). Le
 *     réglage ayant été retiré de l'écran de l'établissement au même commit,
 *     il n'est plus remontable depuis l'interface.
 *  2. `chat_sessions.tokens_used` des sessions antérieures porte l'ancien
 *     comptage (~4 000 par échange au lieu de ~39). `TokenBudget::usedToday()`
 *     sommant toutes les sessions du jour, ces valeurs périmées maintiennent
 *     le plafond dépassé jusqu'à minuit, même avec le code corrigé.
 *
 * Rejouable sans dégât : la borne temporelle est fixe et passée, et les
 * plafonds déjà corrigés ne repassent plus sous le seuil.
 *
 * Le traitement vit dans `App\Services\UsageReset` plutôt qu'en requêtes
 * inline : la commande `carenest:reset-usage` doit faire EXACTEMENT la même
 * chose, et deux copies du seuil et de la borne finiraient par diverger. Le
 * couplage est assumé ; la suite de tests rejoue toutes les migrations à
 * chaque test, une rupture serait immédiatement visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $reset = new UsageReset();

        $reset->resetLegacyCaps();
        $reset->resetLegacyCounters();
    }

    /**
     * Retour arrière IMPOSSIBLE, volontairement sans effet.
     *
     * Les valeurs d'origine ne sont conservées nulle part : le plafond
     * précédent est écrasé, et les compteurs de l'ancienne unité ne sont ni
     * sauvegardés ni convertibles (seul le total par session était stocké,
     * jamais le détail des tours ni la part de prompt système). Rétablir des
     * valeurs inventées remettrait en place le défaut que cette migration
     * corrige. On ne fait donc rien, et on le dit.
     */
    public function down(): void
    {
        // Irréversible par nature : voir le bloc ci-dessus. Aucune donnée à restaurer.
    }
};
