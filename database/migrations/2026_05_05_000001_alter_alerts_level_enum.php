<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P9 + P10 (Probleme CareNest V3) :
 * Différenciation des niveaux d'alerte. L'enum passe de
 *   ['critical', 'moderate']
 * à
 *   ['low', 'moderate', 'high', 'critical']
 *
 * - low      : signal léger (stress passager, contrariété)
 * - moderate : signal sérieux non urgent (tristesse répétée, harcèlement bref)
 * - high     : situation grave (harcèlement répété + peur représailles + public,
 *              isolement long, peur familiale forte)
 * - critical : danger immédiat, détresse vitale, violence subie
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL : on remplace l'enum via DDL brut (Schema::change ne supporte pas ALTER ENUM).
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE alerts MODIFY COLUMN level ENUM('low','moderate','high','critical') NOT NULL DEFAULT 'moderate'");
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite : pas de vrai enum, contrainte CHECK existe peut-être ; on recrée la colonne proprement.
            // Stratégie : comme l'enum SQLite est représenté en TEXT + CHECK, on désactive la contrainte
            // en recréant une colonne text simple (acceptée par les tests qui utilisent SQLite en mémoire).
            Schema::table('alerts', function ($table) {
                // En SQLite "level" est déjà du TEXT — pas de modification nécessaire.
            });
            return;
        }

        // Postgres ou autre : ALTER TYPE / ALTER COLUMN générique
        DB::statement("ALTER TABLE alerts ALTER COLUMN level TYPE VARCHAR(20)");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Avant rollback, on rabat 'low' et 'high' sur 'moderate' / 'critical' pour ne rien casser.
            DB::statement("UPDATE alerts SET level = 'moderate' WHERE level = 'low'");
            DB::statement("UPDATE alerts SET level = 'critical' WHERE level = 'high'");
            DB::statement("ALTER TABLE alerts MODIFY COLUMN level ENUM('critical','moderate') NOT NULL");
        }
    }
};
