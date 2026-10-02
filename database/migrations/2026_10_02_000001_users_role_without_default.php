<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correction du 2026-10-02 — un compte n'est JAMAIS administrateur par défaut.
 *
 * users.role valait « admin » par défaut (migration 2026_09_15_000003) : tout compte créé
 * sans rôle explicite — en particulier par l'inscription publique, fermée dans le même
 * commit — devenait administrateur. La colonne n'a plus de valeur par défaut : un compte
 * reçoit son rôle explicitement à sa création, sinon la base refuse de l'enregistrer.
 *
 * Les rôles des comptes existants ne changent pas : seule la valeur par défaut disparaît.
 *
 * SQLite ne sait pas modifier une valeur par défaut : la table users est reconstruite
 * (copie, suppression, renommage), clés étrangères suspendues le temps de l'opération.
 * Cette suspension n'a aucun effet à l'intérieur d'une transaction ; or users est la table
 * la plus référencée de la base, et supprimer l'ancienne table avec des clés étrangères
 * actives effacerait en cascade les rattachements aux écoles et aux enfants, les
 * délégations et les notifications. La migration refuse donc de tourner dans une
 * transaction SQLite (Laravel n'en ouvre pas autour des migrations sous SQLite).
 */
return new class extends Migration
{
    private const ROLES = ['admin', 'referent', 'parent'];

    public function up(): void
    {
        $this->refuseInsideSqliteTransaction();

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', self::ROLES)->change();
        });
    }

    public function down(): void
    {
        $this->refuseInsideSqliteTransaction();

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', self::ROLES)->default('admin')->change();
        });
    }

    private function refuseInsideSqliteTransaction(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'La table users ne peut pas être reconstruite dans une transaction SQLite : les clés '
                . 'étrangères resteraient actives et la suppression de l\'ancienne table effacerait en '
                . 'cascade les rattachements des comptes. Lancer la migration hors transaction.'
            );
        }
    }
};
