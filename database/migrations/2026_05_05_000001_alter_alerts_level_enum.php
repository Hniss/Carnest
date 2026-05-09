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
            // SQLite stocke les enums avec une contrainte CHECK. Pour les MAJ, on recrée
            // la table alerts avec la nouvelle contrainte étendue (low/moderate/high/critical).
            // On copie les données existantes et on rétablit les FK.
            Schema::create('alerts_tmp_v3', function ($table) {
                $table->id();
                $table->foreignId('session_id')->constrained('chat_sessions')->cascadeOnDelete();
                $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
                $table->foreignId('school_id')->constrained()->cascadeOnDelete();
                $table->enum('type', ['harcelement','detresse','stress','tristesse','danger','isolement']);
                $table->enum('level', ['low','moderate','high','critical']);
                $table->enum('status', ['unread','read','resolved'])->default('unread');
                $table->timestamp('notified_at')->nullable()->default(null);
                $table->timestamps();
                $table->index(['school_id', 'status']);
                $table->index(['child_id', 'created_at']);
            });

            DB::statement('INSERT INTO alerts_tmp_v3 (id, session_id, child_id, school_id, type, level, status, notified_at, created_at, updated_at)
                           SELECT id, session_id, child_id, school_id, type, level, status, notified_at, created_at, updated_at FROM alerts');

            Schema::drop('alerts');
            Schema::rename('alerts_tmp_v3', 'alerts');
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
