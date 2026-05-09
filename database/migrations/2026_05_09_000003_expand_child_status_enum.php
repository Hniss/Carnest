<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P5 (Probleme CareNest V4) : statut enfant à 4 paliers cohérents avec
 * le score climat et les alertes ouvertes :
 *  - 'ok'           : score >= 70 (Stable)
 *  - 'a_surveiller' : 50–69 (À surveiller)
 *  - 'a_suivre'     : 30–49 (Élevé / À suivre)
 *  - 'critique'     : < 30 ou alerte critical 7j non résolue
 *
 * Voir App\Services\ChildStatusResolver pour la logique d'override.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE children MODIFY COLUMN status ENUM('ok','a_surveiller','a_suivre','critique') NOT NULL DEFAULT 'ok'");
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite stocke les enums avec une contrainte CHECK. Pour l'étendre, on
            // recrée la table children avec la nouvelle contrainte (4 paliers).
            // On préserve toutes les données + indexes + FK.
            Schema::create('children_tmp_v4', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->cascadeOnDelete();
                $table->string('name', 150);
                $table->string('email', 150)->unique();
                $table->string('password');
                $table->unsignedTinyInteger('age');
                $table->enum('age_group', ['5-7', '8-11', '12-14']);
                $table->string('classe', 50);
                $table->string('gender', 1)->nullable();
                $table->float('score_enfant')->nullable()->default(null);
                $table->enum('status', ['ok','a_surveiller','a_suivre','critique'])->default('ok');
                $table->timestamp('last_session_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
                $table->index(['school_id', 'status']);
                $table->index(['school_id', 'score_enfant']);
            });

            DB::statement('INSERT INTO children_tmp_v4 (id, school_id, name, email, password, age, age_group, classe, gender, score_enfant, status, last_session_at, remember_token, created_at, updated_at)
                           SELECT id, school_id, name, email, password, age, age_group, classe, gender, score_enfant, status, last_session_at, remember_token, created_at, updated_at FROM children');

            Schema::drop('children');
            Schema::rename('children_tmp_v4', 'children');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Avant rollback, on rabat les nouveaux statuts sur les anciens.
            DB::statement("UPDATE children SET status = 'a_suivre' WHERE status = 'critique'");
            DB::statement("UPDATE children SET status = 'ok' WHERE status = 'a_surveiller'");
            DB::statement("ALTER TABLE children MODIFY COLUMN status ENUM('ok','a_suivre') NOT NULL DEFAULT 'ok'");
        }
    }
};
