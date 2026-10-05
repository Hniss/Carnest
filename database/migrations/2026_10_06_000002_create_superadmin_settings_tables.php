<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace super-admin (2026-10-05) :
 *  - ai_credentials          : une clé d'IA par fournisseur, chiffrée (cast `encrypted`), 4 derniers caractères à part ;
 *  - mail_settings           : la boîte d'envoi (une ligne), mot de passe chiffré ;
 *  - school_alert_recipients : adresses qui reçoivent les e-mails d'alerte d'une école.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->unique();
            $table->text('api_key');
            $table->string('last4', 4);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('host', 190);
            $table->unsignedSmallInteger('port');
            $table->string('encryption', 10)->nullable();
            $table->string('username', 190)->nullable();
            $table->text('password')->nullable();
            $table->string('password_last4', 4)->nullable();
            $table->string('from_address', 190);
            $table->string('from_name', 120);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('school_alert_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('email', 190);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['school_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_alert_recipients');
        Schema::dropIfExists('mail_settings');
        Schema::dropIfExists('ai_credentials');
    }
};
