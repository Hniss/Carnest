<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 2 (MVP v3) — chaîne d'alerte : escalade épuisée, plafond journalier notifié,
 * battements du pager, versions de prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('alerts', 'escalation_exhausted_at')) {
            Schema::table('alerts', function (Blueprint $table) {
                $table->timestamp('escalation_exhausted_at')->nullable()->after('adjudication');
            });
        }

        if (! Schema::hasColumn('children', 'high_usage_notified_on')) {
            Schema::table('children', function (Blueprint $table) {
                $table->date('high_usage_notified_on')->nullable()->after('deactivated_at');
            });
        }

        if (! Schema::hasTable('pager_heartbeats')) {
            Schema::create('pager_heartbeats', function (Blueprint $table) {
                $table->id();
                $table->string('worker', 60);
                $table->timestamp('beat_at');
                $table->index('beat_at');
            });
        }

        if (! Schema::hasTable('prompt_versions')) {
            Schema::create('prompt_versions', function (Blueprint $table) {
                $table->id();
                $table->string('version', 20)->unique();
                $table->string('hash', 64);
                $table->string('target_model')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_versions');
        Schema::dropIfExists('pager_heartbeats');
        if (Schema::hasColumn('children', 'high_usage_notified_on')) {
            Schema::table('children', fn (Blueprint $t) => $t->dropColumn('high_usage_notified_on'));
        }
        if (Schema::hasColumn('alerts', 'escalation_exhausted_at')) {
            Schema::table('alerts', fn (Blueprint $t) => $t->dropColumn('escalation_exhausted_at'));
        }
    }
};
