<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P8 (Probleme CareNest V4) : ajout du genre de l'enfant.
 *  - 'm' = garçon (accords masculins)
 *  - 'f' = fille (accords féminins)
 *  - 'x' = non précisé / refus (le bot évitera les adjectifs marqués)
 *  - null par défaut (rétrocompat avec les enfants déjà créés).
 *
 * Le genre n'est utilisé que côté prompt IA pour produire des accords cohérents
 * (« obligé » vs « obligée »). Il n'est jamais affiché à l'enfant et n'influence
 * aucune logique métier (score, alertes).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE children ADD COLUMN gender ENUM('m','f','x') NULL DEFAULT NULL AFTER classe");
            return;
        }

        // SQLite & autres : colonne string nullable (pas de vrai enum).
        Schema::table('children', function (Blueprint $table) {
            $table->string('gender', 1)->nullable()->after('classe');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
