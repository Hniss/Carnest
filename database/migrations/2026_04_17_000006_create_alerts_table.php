<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['harcelement','detresse','stress','tristesse','danger','isolement']);
            $table->enum('level', ['critical','moderate']);
            $table->enum('status', ['unread','read','resolved'])->default('unread');
            $table->timestamp('notified_at')->nullable()->default(null);
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['child_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
