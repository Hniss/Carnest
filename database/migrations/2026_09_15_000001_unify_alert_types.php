<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D7 (MVP v3) — Nomenclature unique des types d'alerte (7 valeurs) :
 *   harcelement, detresse, pensees_negatives, danger, isolement, stress, humiliation_adulte
 *
 * `tristesse` disparaît : les alertes existantes sont converties en `detresse`.
 * Source de vérité applicative : App\Enums\AlertType.
 */
return new class extends Migration
{
    private const TYPES = ['harcelement', 'detresse', 'pensees_negatives', 'danger', 'isolement', 'stress', 'humiliation_adulte'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("UPDATE alerts SET type = 'detresse' WHERE type = 'tristesse'");
            DB::statement("ALTER TABLE alerts MODIFY COLUMN type ENUM('" . implode("','", self::TYPES) . "') NOT NULL");
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite : la contrainte CHECK ne se modifie pas — recréation de la table
            // avec TOUTES les colonnes actuelles, conversion tristesse -> detresse dans le SELECT.
            // Les contraintes FK sont désactivées le temps du DROP pour éviter tout cascade.
            Schema::withoutForeignKeyConstraints(function () {
                Schema::create('alerts_tmp_v6', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('session_id')->constrained('chat_sessions')->cascadeOnDelete();
                    $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
                    $table->foreignId('school_id')->constrained()->cascadeOnDelete();
                    $table->enum('type', self::TYPES);
                    $table->enum('level', ['low', 'moderate', 'high', 'critical']);
                    $table->enum('status', ['unread', 'read', 'resolved'])->default('unread');
                    $table->timestamp('notified_at')->nullable()->default(null);
                    $table->timestamps();
                    $table->index(['school_id', 'status']);
                    $table->index(['child_id', 'created_at']);
                });

                DB::statement("INSERT INTO alerts_tmp_v6 (id, session_id, child_id, school_id, type, level, status, notified_at, created_at, updated_at)
                               SELECT id, session_id, child_id, school_id,
                                      CASE WHEN type = 'tristesse' THEN 'detresse' ELSE type END,
                                      level, status, notified_at, created_at, updated_at
                               FROM alerts");

                Schema::drop('alerts');
                Schema::rename('alerts_tmp_v6', 'alerts');
                $this->canonicalizeSqliteIndexes('alerts', 'alerts_tmp_v6', [
                    ['columns' => ['school_id', 'status'], 'unique' => false],
                    ['columns' => ['child_id', 'created_at'], 'unique' => false],
                ]);
            });
            return;
        }

        // Autres SGBD : conversion des données, colonne élargie en VARCHAR.
        DB::statement("UPDATE alerts SET type = 'detresse' WHERE type = 'tristesse'");
        DB::statement('ALTER TABLE alerts ALTER COLUMN type TYPE VARCHAR(30)');
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Rabat des pensées négatives sur detresse (type le plus proche de l'ancienne nomenclature).
            DB::statement("UPDATE alerts SET type = 'detresse' WHERE type = 'pensees_negatives'");
            DB::statement("ALTER TABLE alerts MODIFY COLUMN type ENUM('harcelement','detresse','stress','tristesse','danger','isolement','humiliation_adulte') NOT NULL");
        }
        // SQLite : la contrainte élargie est conservée (aucune donnée n'est perdue) ;
        // les pensees_negatives sont rabattues sur detresse pour rester lisibles par l'ancien code.
        if ($driver === 'sqlite') {
            DB::statement("UPDATE alerts SET type = 'detresse' WHERE type = 'pensees_negatives'");
        }
    }

    /**
     * SQLite conserve le nom des index de la table temporaire après RENAME
     * (ex. alerts_tmp_v6_school_id_status_index). On les recrée sous leur nom
     * canonique pour que la migration reste rejouable (rollback puis migrate).
     *
     * @param array<int, array{columns: string[], unique: bool}> $indexes
     */
    private function canonicalizeSqliteIndexes(string $table, string $tmpTable, array $indexes): void
    {
        $tmpIndexes = DB::table('sqlite_master')
            ->where('type', 'index')
            ->where('tbl_name', $table)
            ->where('name', 'like', $tmpTable . '_%')
            ->pluck('name');

        foreach ($tmpIndexes as $name) {
            DB::statement('DROP INDEX IF EXISTS "' . $name . '"');
        }

        Schema::table($table, function (Blueprint $t) use ($indexes) {
            foreach ($indexes as $index) {
                $index['unique'] ? $t->unique($index['columns']) : $t->index($index['columns']);
            }
        });
    }
};
