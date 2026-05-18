<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feature « Suivi psychique longitudinal » : les notes admin peuvent désormais
 * être rattachées directement à un enfant (note libre sur profil), pas
 * uniquement à une alerte. On ajoute child_id et on rend alert_id nullable.
 *
 * On préserve les notes existantes : leur alert_id reste, child_id sera
 * rétro-rempli depuis l'alerte associée.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // 1) Ajouter child_id nullable
        Schema::table('admin_notes', function (Blueprint $table) {
            $table->foreignId('child_id')
                ->nullable()
                ->after('id')
                ->constrained('children')
                ->cascadeOnDelete();
            $table->index(['child_id', 'created_at']);
        });

        // 2) Rétro-remplir child_id depuis l'alerte associée (notes historiques)
        DB::statement('UPDATE admin_notes SET child_id = (SELECT child_id FROM alerts WHERE alerts.id = admin_notes.alert_id) WHERE child_id IS NULL AND alert_id IS NOT NULL');

        // 3) Rendre alert_id nullable
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE admin_notes MODIFY COLUMN alert_id BIGINT UNSIGNED NULL');
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite ne supporte pas ALTER COLUMN ; recréer la table.
            Schema::create('admin_notes_tmp_v5', function (Blueprint $table) {
                $table->id();
                $table->foreignId('child_id')->nullable()->constrained('children')->cascadeOnDelete();
                $table->foreignId('alert_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->text('content');
                $table->timestamps();
                $table->index(['child_id', 'created_at']);
            });

            DB::statement('INSERT INTO admin_notes_tmp_v5 (id, child_id, alert_id, user_id, content, created_at, updated_at)
                           SELECT id, child_id, alert_id, user_id, content, created_at, updated_at FROM admin_notes');

            Schema::drop('admin_notes');
            Schema::rename('admin_notes_tmp_v5', 'admin_notes');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Re-rendre alert_id NOT NULL (nettoyer les orphelines auparavant).
            DB::statement('DELETE FROM admin_notes WHERE alert_id IS NULL');
            DB::statement('ALTER TABLE admin_notes MODIFY COLUMN alert_id BIGINT UNSIGNED NOT NULL');

            Schema::table('admin_notes', function (Blueprint $table) {
                $table->dropForeign(['child_id']);
                $table->dropIndex(['child_id', 'created_at']);
                $table->dropColumn('child_id');
            });
            return;
        }

        if ($driver === 'sqlite') {
            // Rollback SQLite : recréer la table sans child_id.
            DB::statement('DELETE FROM admin_notes WHERE alert_id IS NULL');

            Schema::create('admin_notes_rollback', function (Blueprint $table) {
                $table->id();
                $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->text('content');
                $table->timestamps();
            });

            DB::statement('INSERT INTO admin_notes_rollback (id, alert_id, user_id, content, created_at, updated_at)
                           SELECT id, alert_id, user_id, content, created_at, updated_at FROM admin_notes');

            Schema::drop('admin_notes');
            Schema::rename('admin_notes_rollback', 'admin_notes');
        }
    }
};
