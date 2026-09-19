<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 2 (MVP v3) — chaîne d'alerte : escalade épuisée, plafond journalier notifié.
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
    }

    public function down(): void
    {
        if (Schema::hasColumn('children', 'high_usage_notified_on')) {
            Schema::table('children', fn (Blueprint $t) => $t->dropColumn('high_usage_notified_on'));
        }
        if (Schema::hasColumn('alerts', 'escalation_exhausted_at')) {
            Schema::table('alerts', fn (Blueprint $t) => $t->dropColumn('escalation_exhausted_at'));
        }
    }
};
