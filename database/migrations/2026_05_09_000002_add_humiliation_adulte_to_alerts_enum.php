<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P10 (Probleme CareNest V4) : ajout du type d'alerte 'humiliation_adulte'
 * pour distinguer les humiliations exercées par un adulte de l'école
 * (enseignant, directeur, surveillant) du harcèlement entre pairs.
 *
 * Ce type doit déclencher au minimum un niveau d'alerte 'high' (cf. AlertLevelResolver).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE alerts MODIFY COLUMN type ENUM('harcelement','detresse','stress','tristesse','danger','isolement','humiliation_adulte') NOT NULL");
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite — recréation de la table pour étendre la contrainte CHECK sur "type".
            Schema::create('alerts_tmp_v4', function (Blueprint $table) {
                $table->id();
                $table->foreignId('session_id')->constrained('chat_sessions')->cascadeOnDelete();
                $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
                $table->foreignId('school_id')->constrained()->cascadeOnDelete();
                $table->enum('type', ['harcelement','detresse','stress','tristesse','danger','isolement','humiliation_adulte']);
                $table->enum('level', ['low','moderate','high','critical']);
                $table->enum('status', ['unread','read','resolved'])->default('unread');
                $table->timestamp('notified_at')->nullable()->default(null);
                $table->timestamps();
                $table->index(['school_id', 'status']);
                $table->index(['child_id', 'created_at']);
            });

            DB::statement('INSERT INTO alerts_tmp_v4 (id, session_id, child_id, school_id, type, level, status, notified_at, created_at, updated_at)
                           SELECT id, session_id, child_id, school_id, type, level, status, notified_at, created_at, updated_at FROM alerts');

            Schema::drop('alerts');
            Schema::rename('alerts_tmp_v4', 'alerts');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Avant rollback, on rabat les humiliation_adulte en harcelement.
            DB::statement("UPDATE alerts SET type = 'harcelement' WHERE type = 'humiliation_adulte'");
            DB::statement("ALTER TABLE alerts MODIFY COLUMN type ENUM('harcelement','detresse','stress','tristesse','danger','isolement') NOT NULL");
        }
    }
};
