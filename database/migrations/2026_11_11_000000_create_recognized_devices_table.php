<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recognized_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            // The browser holds the raw token; only its hash is stored here.
            $table->string('token_hash', 64);
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->unique(['user_id', 'token_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recognized_devices');
    }
};
