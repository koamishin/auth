<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create('oauth_grant_families', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('oauth_clients')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->char('revocation_hash', 64)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        $schema->create('oauth_grant_family_tokens', function (Blueprint $table): void {
            $table->char('access_token_id', 80)->primary();
            $table->foreign('access_token_id')->references('id')->on('oauth_access_tokens')->cascadeOnDelete();
            $table->foreignUuid('family_id')->constrained('oauth_grant_families')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('oauth_grant_family_tokens');
        $schema->dropIfExists('oauth_grant_families');
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
