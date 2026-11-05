<?php

declare(strict_types=1);

use App\Jobs\DispatchWebhookJob;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Users\LaravelUserImporter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

test('development and production environments maintain isolated user directories', function (): void {
    Role::query()->firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->create([
        'name' => 'Acme Cloud',
        'slug' => 'acme-cloud',
    ]);

    /** @var TenantApplication $app */
    $app = TenantApplication::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Acme WebApp',
        'framework' => 'nextjs',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:3000/callback'],
    ]);

    $devUser = User::factory()->create(['name' => 'Dev Tester', 'email' => 'dev@acme.test']);
    $prodUser = User::factory()->create(['name' => 'Prod Customer', 'email' => 'customer@acme.com']);

    // Enroll Dev User in development sandbox
    $app->users()->attach($devUser, [
        'environment' => 'development',
        'role' => 'tester',
    ]);

    // Enroll Prod User in production
    $app->users()->attach($prodUser, [
        'environment' => 'production',
        'role' => 'customer',
    ]);

    // Development query only returns dev user
    $devUsers = $app->users('development')->get();
    expect($devUsers->count())->toBe(1);
    expect($devUsers->first()?->email)->toBe('dev@acme.test');

    // Production query only returns prod user
    $prodUsers = $app->users('production')->get();
    expect($prodUsers->count())->toBe(1);
    expect($prodUsers->first()?->email)->toBe('customer@acme.com');
});

test('can import users from other laravel applications and preserve existing bcrypt password hashes', function (): void {
    Role::query()->firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->create([
        'name' => 'Acme Labs',
        'slug' => 'acme-labs',
    ]);

    /** @var TenantApplication $app */
    $app = TenantApplication::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Acme API',
        'framework' => 'laravel',
        'environment' => 'production',
        'redirect_uris' => ['https://api.acme.com/callback'],
    ]);

    $importer = new LaravelUserImporter;

    $knownPassword = 'laravel_legacy_secret_password';
    $originalBcryptHash = Hash::make($knownPassword);

    $jsonPayload = json_encode([
        [
            'name' => 'Imported Artisan',
            'email' => 'artisan@laravel.com',
            'password' => $originalBcryptHash,
            'email_verified_at' => '2026-05-01 12:00:00',
        ],
        [
            'name' => 'Taylor Developer',
            'email' => 'taylor@laravel.com',
            'password' => $originalBcryptHash,
            'email_verified_at' => '2026-05-01 12:00:00',
        ],
    ], JSON_THROW_ON_ERROR);

    $result = $importer->importFromJson($jsonPayload, $tenant, $app, 'production', [
        'auto_verify' => true,
    ]);

    expect($result['imported'])->toBe(2);
    expect($result['errors'])->toBeEmpty();

    /** @var User $importedUser */
    $importedUser = User::query()->where('email', 'artisan@laravel.com')->first();
    expect($importedUser)->not->toBeNull();

    // Verbatim hash was preserved
    expect($importedUser->password)->toBe($originalBcryptHash);
    expect($importedUser->hasVerifiedEmail())->toBeTrue();

    // User can authenticate with their existing legacy password seamlessly!
    expect(Hash::check($knownPassword, $importedUser->password))->toBeTrue();

    // User is bound to the production environment of this application
    expect($app->users('production')->where('email', 'artisan@laravel.com')->exists())->toBeTrue();
});

test('can import users from csv format into a specific environment', function (): void {
    Role::query()->firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->create([
        'name' => 'CSV Import Org',
        'slug' => 'csv-import-org',
    ]);

    /** @var TenantApplication $app */
    $app = TenantApplication::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Storefront App',
        'framework' => 'vue',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:5173/callback'],
    ]);

    $importer = new LaravelUserImporter;

    $csv = <<<'CSV'
name,email,password,role
John Doe,john@imported.test,samplePassword123,member
Jane Smith,jane@imported.test,samplePassword456,admin
CSV;

    $result = $importer->importFromCsv($csv, $tenant, $app, 'development', [
        'auto_verify' => true,
    ]);

    expect($result['imported'])->toBe(2);

    /** @var User $john */
    $john = User::query()->where('email', 'john@imported.test')->first();
    expect($john)->not->toBeNull();
    expect(Hash::check('samplePassword123', $john->password))->toBeTrue();

    expect($app->users('development')->where('email', 'john@imported.test')->exists())->toBeTrue();
});

test('import creates separate users for each environment and emits creation only on enrollment', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Import org', 'slug' => 'import-org']);
    $application = $tenant->applications()->create([
        'name' => 'Import app', 'framework' => 'laravel', 'environment' => 'development', 'redirect_uris' => [],
    ]);
    $development = $application->environments()->create(['environment' => 'development', 'redirect_uris' => []]);
    $production = $application->environments()->create(['environment' => 'production', 'redirect_uris' => []]);
    foreach ([$development, $production] as $environment) {
        WebhookEndpoint::query()->create([
            'name' => $environment->environment,
            'url' => 'https://example.test/webhook',
            'secret' => 'secret',
            'tenant_application_environment_id' => $environment->id,
            'events' => ['user.created'],
            'is_active' => true,
        ]);
    }
    Queue::fake([DispatchWebhookJob::class]);
    $user = User::factory()->create(['name' => 'Portal member', 'email' => 'member@example.test']);
    $importer = new LaravelUserImporter;
    $row = [['id' => $user->id, 'name' => 'App member', 'email' => $user->email]];

    $developmentResult = $importer->importFromData($row, $tenant, $application, 'development');
    $productionResult = $importer->importFromData($row, $tenant, $application, 'production');
    $reimportResult = $importer->importFromData($row, $tenant, $application, 'production');

    $developmentUser = User::query()->where('identity_namespace', 'environment:'.$development->getKey())->where('email', $user->email)->firstOrFail();
    $productionUser = User::query()->where('identity_namespace', 'environment:'.$production->getKey())->where('email', $user->email)->firstOrFail();
    expect($developmentResult['imported'])->toBe(1);
    expect($productionResult['imported'])->toBe(1);
    expect($reimportResult['updated'])->toBe(1);
    expect($user->fresh()->name)->toBe('Portal member');
    expect($developmentUser->id)->not->toBe($productionUser->id)->not->toBe($user->id);
    expect($tenant->users()->whereKey($user)->exists())->toBeFalse();
    expect($tenant->users()->whereKey($developmentUser)->exists())->toBeTrue();
    expect($tenant->users()->whereKey($productionUser)->exists())->toBeTrue();
    expect($application->users('development')->whereKey($developmentUser)->exists())->toBeTrue();
    expect($application->users('production')->whereKey($productionUser)->exists())->toBeTrue();
    expect($application->users()->whereKey($user)->exists())->toBeFalse();
    expect($application->users('production')->whereKey($developmentUser)->exists())->toBeFalse();
    Queue::assertPushed(DispatchWebhookJob::class, 2);
    Queue::assertPushed(DispatchWebhookJob::class, fn (DispatchWebhookJob $job): bool => $job->event === 'user.created'
        && $job->endpoint->tenant_application_environment_id === $development->id);
    Queue::assertPushed(DispatchWebhookJob::class, fn (DispatchWebhookJob $job): bool => $job->event === 'user.created'
        && $job->endpoint->tenant_application_environment_id === $production->id);
});

test('import rejects an application owned by a different organization', function (): void {
    $tenant = Tenant::query()->create(['name' => 'First org', 'slug' => 'first-org']);
    $other = Tenant::query()->create(['name' => 'Second org', 'slug' => 'second-org']);
    $application = $other->applications()->create([
        'name' => 'Other app', 'framework' => 'laravel', 'environment' => 'development', 'redirect_uris' => [],
    ]);

    expect(fn (): array => (new LaravelUserImporter)->importFromData(
        [['name' => 'Member', 'email' => 'member@example.test']], $tenant, $application
    ))->toThrow(InvalidArgumentException::class);
    expect(User::query()->where('email', 'member@example.test')->exists())->toBeFalse();
});

test('reimport without updating details preserves existing organization and application roles', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Role org', 'slug' => 'role-org']);
    $application = $tenant->applications()->create([
        'name' => 'Role app', 'framework' => 'laravel', 'environment' => 'production', 'redirect_uris' => [],
    ]);
    $environment = $application->getEnvironmentConfig('production');
    $user = User::factory()->create([
        'name' => 'Original name',
        'email' => 'owner@example.test',
        'identity_namespace' => 'environment:'.$environment->getKey(),
    ]);
    $tenant->users()->attach($user, ['role' => 'owner']);
    $application->users()->attach($user, ['environment' => 'production', 'role' => 'admin']);

    (new LaravelUserImporter)->importFromData(
        [['name' => 'Changed name', 'email' => $user->email, 'role' => 'member']],
        $tenant,
        $application,
        'production',
        ['update_existing' => false],
    );

    expect($user->fresh()->name)->toBe('Original name');
    expect($tenant->users()->whereKey($user)->firstOrFail()->getRelationValue('pivot')->getAttribute('role'))->toBe('owner');
    expect($application->users('production')->whereKey($user)->firstOrFail()->getRelationValue('pivot')->getAttribute('role'))->toBe('admin');
});

test('imports isolate identical emails across applications and reuse the matching namespace', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Applications org', 'slug' => 'applications-org']);
    $first = $tenant->applications()->create([
        'name' => 'First app', 'framework' => 'laravel', 'environment' => 'development', 'redirect_uris' => [],
    ]);
    $second = $tenant->applications()->create([
        'name' => 'Second app', 'framework' => 'laravel', 'environment' => 'development', 'redirect_uris' => [],
    ]);
    $importer = new LaravelUserImporter;
    $row = [['name' => 'First name', 'email' => 'shared@example.test']];

    $firstResult = $importer->importFromData($row, $tenant, $first);
    $secondResult = $importer->importFromData($row, $tenant, $second);
    $updateResult = $importer->importFromData([['name' => 'Updated name', 'email' => 'shared@example.test']], $tenant, $first);

    $firstUser = $first->users('development')->firstOrFail();
    $secondUser = $second->users('development')->firstOrFail();
    expect($firstResult['imported'])->toBe(1);
    expect($secondResult['imported'])->toBe(1);
    expect($updateResult['updated'])->toBe(1);
    expect($firstUser->id)->not->toBe($secondUser->id);
    expect($firstUser->name)->toBe('Updated name');
    expect($secondUser->name)->toBe('First name');
    expect($firstUser->getAttribute('identity_namespace'))->toBe('environment:'.$first->getEnvironmentConfig('development')->getKey());
    expect($secondUser->getAttribute('identity_namespace'))->toBe('environment:'.$second->getEnvironmentConfig('development')->getKey());
});

test('organization-only imports use the portal namespace without updating application users', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Portal import org', 'slug' => 'portal-import-org']);
    $application = $tenant->applications()->create([
        'name' => 'Portal import app', 'framework' => 'laravel', 'environment' => 'development', 'redirect_uris' => [],
    ]);
    $importer = new LaravelUserImporter;
    $row = [['name' => 'Application user', 'email' => 'shared@example.test']];
    $importer->importFromData($row, $tenant, $application);

    $result = $importer->importFromData([['name' => 'Portal user', 'email' => 'shared@example.test']], $tenant);
    $repeatResult = $importer->importFromData([['name' => 'Updated portal user', 'email' => 'shared@example.test']], $tenant);

    $applicationUser = $application->users('development')->firstOrFail();
    $portalUser = User::query()->where('identity_namespace', 'portal')->where('email', 'shared@example.test')->firstOrFail();
    expect($result['imported'])->toBe(1);
    expect($repeatResult['updated'])->toBe(1);
    expect($portalUser->id)->not->toBe($applicationUser->id);
    expect($portalUser->name)->toBe('Updated portal user');
    expect($applicationUser->name)->toBe('Application user');
    expect($tenant->users()->whereKey($portalUser)->exists())->toBeTrue();
});

test('organization-only imports do not attach or update an existing portal user from another organization', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Import target', 'slug' => 'import-target']);
    $user = User::factory()->create(['name' => 'Original', 'email' => 'existing@example.test']);

    $result = (new LaravelUserImporter)->importFromData(
        [['name' => 'Changed', 'email' => $user->email]],
        $tenant,
    );

    expect($result['skipped'])->toBe(1);
    expect($result['updated'])->toBe(0);
    expect($user->fresh()->name)->toBe('Original');
    expect($tenant->users()->whereKey($user)->exists())->toBeFalse();
});

test('organization-only imports do not update portal administrators even when they are members', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Import target', 'slug' => 'import-admin-target']);
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create(['name' => 'Original', 'email' => 'admin@example.test']);
    $admin->assignRole('super_admin');
    $tenant->users()->attach($admin, ['role' => 'owner']);

    $result = (new LaravelUserImporter)->importFromData([['name' => 'Changed', 'email' => $admin->email]], $tenant);

    expect($result['skipped'])->toBe(1);
    expect($admin->fresh()->name)->toBe('Original');
    expect($tenant->users()->whereKey($admin)->firstOrFail()->getRelationValue('pivot')->getAttribute('role'))->toBe('owner');
});
