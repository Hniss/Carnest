<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 1 (MVP v3) :
 *  - admin_notes.referent_id (D2) : auteur de la note interne côté référent,
 *    rétro-rempli depuis user_id (colonne d'auteur existante).
 *  - alerts.reopened_from_id : mention « réouverture » quand une nouvelle
 *    alerte high/critical suit une clôture de moins de 30 jours (même enfant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_notes', function (Blueprint $table) {
            $table->foreignId('referent_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
        DB::statement('UPDATE admin_notes SET referent_id = user_id WHERE referent_id IS NULL');

        Schema::table('alerts', function (Blueprint $table) {
            $table->unsignedBigInteger('reopened_from_id')->nullable()->after('adjudication');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', fn (Blueprint $t) => $t->dropColumn('reopened_from_id'));
        Schema::table('admin_notes', function (Blueprint $table) {
            $table->dropForeign(['referent_id']);
            $table->dropColumn('referent_id');
        });
    }
};
