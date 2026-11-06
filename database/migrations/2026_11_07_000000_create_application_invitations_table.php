<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_application_environment_id')
                ->constrained('tenant_application_environments')
                ->cascadeOnDelete();
            $table->string('email');
            $table->string('role')->default('member');
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Looked up as "the live invitation for this address in this environment".
            $table->index(['tenant_application_environment_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_invitations');
    }
};
