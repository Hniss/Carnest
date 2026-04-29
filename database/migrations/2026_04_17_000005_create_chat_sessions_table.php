<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->enum('zone', ['green','yellow','orange','red'])->nullable()->default(null);
            $table->text('ai_summary')->nullable();
            $table->boolean('low_confidence')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable()->default(null);
            $table->timestamps();

            $table->index(['child_id', 'ended_at']);
            $table->index(['school_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
