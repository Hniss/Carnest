<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 1 (MVP v3) §4 :
 *  - users.deactivated_at : désactivation d'un compte école (référent, administrateur) ;
 *    la connexion est refusée tant que la colonne est renseignée.
 *  - school_settings.notification_channels : canaux de notification de l'école (app / email / sms).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('phone');
        });
        Schema::table('school_settings', function (Blueprint $table) {
            $table->json('notification_channels')->nullable()->after('admin_phone');
        });
    }

    public function down(): void
    {
        Schema::table('school_settings', fn (Blueprint $t) => $t->dropColumn('notification_channels'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('deactivated_at'));
    }
};
