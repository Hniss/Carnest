<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 1 (MVP v3) §4 :
 *  - users.deactivated_at : désactivation d'un compte école (référent, administrateur) ;
 *    la connexion est refusée tant que la colonne est renseignée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('deactivated_at'));
    }
};
