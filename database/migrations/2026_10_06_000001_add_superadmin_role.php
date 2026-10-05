<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Espace super-admin (2026-10-05) — nouveau rôle `superadmin` (fondateurs CareNest).
 *
 * Même contrainte que 2026_10_02_000001 : sous SQLite la table users est reconstruite,
 * la migration refuse donc de tourner dans une transaction (suppression en cascade des
 * rattachements sinon). Aucune valeur par défaut : un compte reçoit son rôle explicitement.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->refuseInsideSqliteTransaction();

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'referent', 'parent', 'superadmin'])->change();
        });
    }

    public function down(): void
    {
        $this->refuseInsideSqliteTransaction();

        if (DB::table('users')->where('role', 'superadmin')->exists()) {
            throw new RuntimeException('Des comptes super-admin existent : les supprimer ou les convertir avant le retour arrière.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'referent', 'parent'])->change();
        });
    }

    private function refuseInsideSqliteTransaction(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException('La table users ne peut pas être reconstruite dans une transaction SQLite.');
        }
    }
};
