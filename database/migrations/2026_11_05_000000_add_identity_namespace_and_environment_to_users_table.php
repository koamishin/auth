<?php

declare(strict_types=1);

use App\Models\User;
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
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->dropUnique(['username']);
            $table->dropUnique(['phone_number']);

            $table->string('identity_namespace')->default(User::PORTAL_IDENTITY_NAMESPACE)->after('id');
            $table->foreignId('tenant_application_environment_id')
                ->nullable()
                ->after('identity_namespace')
                ->constrained('tenant_application_environments')
                ->nullOnDelete();

            $table->unique(['identity_namespace', 'email']);
            $table->unique(['identity_namespace', 'username']);
            $table->unique(['identity_namespace', 'phone_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['identity_namespace', 'email']);
            $table->dropUnique(['identity_namespace', 'username']);
            $table->dropUnique(['identity_namespace', 'phone_number']);

            $table->dropConstrainedForeignId('tenant_application_environment_id');
            $table->dropColumn('identity_namespace');

            $table->unique('email');
            $table->unique('username');
            $table->unique('phone_number');
        });
    }
};
