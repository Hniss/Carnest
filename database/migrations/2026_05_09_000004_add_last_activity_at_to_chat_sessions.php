<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 (Probleme CareNest V4) : sauvegarde des sessions abandonnées.
 *
 * Ajoute `last_activity_at` sur chat_sessions pour permettre au job
 * `CloseIdleSessions` (cron toutes les 2 min) de fermer automatiquement
 * les sessions inactives depuis ≥ 5 minutes.
 *
 * Aucun message brut n'est stocké — on n'enregistre que le timestamp de
 * dernière activité (touché à chaque sendMessage / fetchReply).
 *
 * Index composé sur (ended_at, last_activity_at) pour optimiser la requête
 * du job : `WHERE ended_at IS NULL AND last_activity_at < now() - 5 min`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('ended_at');
            $table->index(['ended_at', 'last_activity_at'], 'chat_sessions_idle_idx');
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropIndex('chat_sessions_idle_idx');
            $table->dropColumn('last_activity_at');
        });
    }
};
