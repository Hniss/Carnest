<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->string('password');
            $table->unsignedTinyInteger('age');
            $table->enum('age_group', ['5-7', '8-11', '12-14']);
            $table->string('classe', 50);
            $table->float('score_enfant')->nullable()->default(null);
            $table->enum('status', ['ok', 'a_suivre'])->default('ok');
            $table->timestamp('last_session_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'score_enfant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('children');
    }
};
