<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant_applications', function (Blueprint $table): void {
            $table->string('framework')->nullable()->default(null)->change();
        });

        Schema::table('tenant_application_environments', function (Blueprint $table): void {
            $table->string('publishable_key')->nullable()->after('oauth_client_id');
            $table->string('secret_key_lookup', 64)->nullable()->after('publishable_key');
            $table->string('secret_key_hash')->nullable()->after('secret_key_lookup');
            $table->string('secret_key_prefix', 32)->nullable()->after('secret_key_hash');
            $table->timestamp('secret_key_rotated_at')->nullable()->after('secret_key_prefix');
            $table->string('client_type', 32)->nullable()->after('secret_key_rotated_at');
        });

        DB::table('tenant_application_environments')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $env): void {
                $updates = [];

                if ($env->publishable_key === null || $env->publishable_key === '') {
                    $pkPrefix = $env->environment === 'production' ? 'pk_live_' : 'pk_test_';
                    do {
                        $candidate = $pkPrefix.Str::random(32);
                    } while (DB::table('tenant_application_environments')->where('publishable_key', $candidate)->exists());

                    $updates['publishable_key'] = $candidate;
                }

                if ($env->client_type === null && $env->oauth_client_id !== null) {
                    $client = DB::table('oauth_clients')->where('id', $env->oauth_client_id)->first();
                    if ($client !== null) {
                        $updates['client_type'] = ($client->secret !== null && $client->secret !== '')
                            ? 'confidential'
                            : 'public';
                    }
                }

                if ($updates !== []) {
                    $updates['updated_at'] = now();
                    DB::table('tenant_application_environments')->where('id', $env->id)->update($updates);
                }
            });

        Schema::table('tenant_application_environments', function (Blueprint $table): void {
            $table->unique('publishable_key');
            $table->unique('secret_key_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_application_environments', function (Blueprint $table): void {
            $table->dropUnique(['publishable_key']);
            $table->dropUnique(['secret_key_lookup']);
        });

        Schema::table('tenant_application_environments', function (Blueprint $table): void {
            $table->dropColumn([
                'publishable_key',
                'secret_key_lookup',
                'secret_key_hash',
                'secret_key_prefix',
                'secret_key_rotated_at',
                'client_type',
            ]);
        });

        DB::table('tenant_applications')->whereNull('framework')->update(['framework' => 'laravel']);

        Schema::table('tenant_applications', function (Blueprint $table): void {
            $table->string('framework')->default('laravel')->nullable(false)->change();
        });
    }
};
