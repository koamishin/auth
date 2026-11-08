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
        Schema::create('application_waitlist_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_application_environment_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('email');
            $table->timestamp('invited_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_application_environment_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_waitlist_entries');
    }
};
