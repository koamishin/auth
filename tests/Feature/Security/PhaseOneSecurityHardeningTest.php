<?php

declare(strict_types=1);

use App\Enums\EmailCodePurpose;
use App\Models\ApplicationInvitation;
use App\Models\Client;
use App\Models\MultiSessionAccount;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Auth\EmailCodeService;
use App\Services\OAuth\ApplicationAuthentication;
use App\Services\Users\LaravelUserImporter;
use App\Support\OutboundUrlValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

test('user activity log excludes sensitive authentication columns', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('initial-secret-pw'),
        'two_factor_secret' => 'totp-secret-value',
        'two_factor_recovery_codes' => 'recovery-code-value',
        'app_authentication_secret' => 'filament-secret-value',
        'app_authentication_recovery_codes' => 'filament-recovery-value',
        'remember_token' => 'remember-token-value',
    ]);

    $user->forceFill([
        'name' => 'Updated Name',
        'two_factor_secret' => 'new-totp-secret-value',
    ])->save();

    $activities = Activity::query()->where('subject_id', $user->getKey())->get();

    foreach ($activities as $activity) {
        $serialized = json_encode($activity->properties);
        expect($serialized)
            ->not->toContain('totp-secret-value')
            ->not->toContain('new-totp-secret-value')
            ->not->toContain('recovery-code-value')
            ->not->toContain('filament-secret-value')
            ->not->toContain('filament-recovery-value')
            ->not->toContain('remember-token-value');
    }
});

test('impersonation restricts access to portal super_admin and protects privileged or banned accounts', function (): void {
    $superAdminRole = Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $adminRole = Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole($superAdminRole);

    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $member = User::factory()->create();
    $banned = User::factory()->create(['banned_at' => now()]);

    expect($superAdmin->canImpersonate())->toBeTrue()
        ->and($admin->canImpersonate())->toBeFalse()
        ->and($superAdmin->canBeImpersonated())->toBeFalse()
        ->and($admin->canBeImpersonated())->toBeFalse()
        ->and($banned->canBeImpersonated())->toBeFalse()
        ->and($member->canBeImpersonated())->toBeTrue();
});

test('filament-only mfa flags do not satisfy oauth application require_mfa', function (): void {
    $tenant = Tenant::query()->create(['name' => 'MFA Tenant', 'slug' => 'mfa-tenant', 'is_active' => true]);
    $app = TenantApplication::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'MFA App',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://app.example.com/callback'],
    ]);
    $env = $app->environments()->create([
        'environment' => 'development',
        'redirect_uris' => ['https://app.example.com/callback'],
        'settings' => ['require_mfa' => true, 'passkeys_satisfy_mfa' => false],
    ]);

    $user = User::factory()->forEnvironment($env)->create([
        'has_email_authentication' => true,
        'app_authentication_secret' => 'filament-only-secret',
    ]);

    $request = Request::create('/oauth/authorize', 'GET');
    $request->setLaravelSession(app('session.store'));

    expect(app(ApplicationAuthentication::class)->multiFactorSatisfied($request, $env, $user))->toBeFalse();
});

test('attachMembership never demotes a portal super_admin identity_namespace', function (): void {
    $superAdminRole = Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $superAdmin = User::factory()->create([
        'identity_namespace' => User::PORTAL_IDENTITY_NAMESPACE,
        'tenant_application_environment_id' => null,
    ]);
    $superAdmin->assignRole($superAdminRole);

    $client = new Client;
    $client->forceFill([
        'name' => 'Open App',
        'secret' => 'open-secret',
        'redirect_uris' => ['https://open.example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ])->save();

    $tenant = Tenant::query()->create(['name' => 'Portal Safe', 'slug' => 'portal-safe', 'is_active' => true]);
    $app = TenantApplication::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Open App',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://open.example.com/callback'],
        'oauth_client_id' => $client->getKey(),
    ]);
    $env = $app->environments()->create([
        'environment' => 'development',
        'oauth_client_id' => $client->getKey(),
        'redirect_uris' => ['https://open.example.com/callback'],
        'settings' => ['allow_signups' => true],
    ]);

    app(ApplicationAuthentication::class)->enroll($env, $superAdmin);

    $superAdmin->refresh();
    expect($superAdmin->identity_namespace)->toBe(User::PORTAL_IDENTITY_NAMESPACE)
        ->and($superAdmin->tenant_application_environment_id)->toBeNull()
        ->and($superAdmin->isSystemAdmin())->toBeTrue();
});

test('signin alert revocation revokes multi-session switch tokens', function (): void {
    $user = User::factory()->create();
    MultiSessionAccount::query()->create([
        'user_id' => $user->getKey(),
        'token_hash' => hash('sha256', 'sample-token'),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'created_at' => now(),
        'expires_at' => now()->addDays(30),
    ]);

    expect(MultiSessionAccount::query()->where('user_id', $user->getKey())->count())->toBe(1);

    $signedUrl = URL::signedRoute('signin-alert.revoke', [
        'user' => $user->getKey(),
        'session' => 'nonexistent123',
    ]);

    $this->get($signedUrl)->assertOk();

    expect(MultiSessionAccount::query()->where('user_id', $user->getKey())->count())->toBe(0);
});

test('outbound url validator blocks ssrf targets and open redirects', function (): void {
    expect(fn () => OutboundUrlValidator::validateWebhookUrl('https://127.0.0.1/hook'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => OutboundUrlValidator::validateWebhookUrl('http://169.254.169.254/latest/meta-data/'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => OutboundUrlValidator::validateWebhookUrl('https://10.0.0.5/hook'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => OutboundUrlValidator::validateWebhookUrl('https://localhost/hook'))
        ->toThrow(InvalidArgumentException::class);

    expect(OutboundUrlValidator::isSameOrigin('https://portal.example.com/admin', 'https://portal.example.com'))->toBeTrue()
        ->and(OutboundUrlValidator::isSameOrigin('https://portal.example.com.evil.com/admin', 'https://portal.example.com'))->toBeFalse()
        ->and(OutboundUrlValidator::isSameOrigin('https://portal.example.com@evil.com/', 'https://portal.example.com'))->toBeFalse()
        ->and(OutboundUrlValidator::isSameOrigin('//evil.com', 'https://portal.example.com'))->toBeFalse();
});

test('database user importer rejects sqlite and private hosts', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Import Tenant', 'slug' => 'import-tenant', 'is_active' => true]);
    $importer = app(LaravelUserImporter::class);

    $sqliteResult = $importer->importFromDatabase([
        'driver' => 'sqlite',
        'database' => database_path('database.sqlite'),
    ], $tenant);

    expect($sqliteResult['errors'][0])->toContain('Unsupported database driver');

    $ssrfResult = $importer->importFromDatabase([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app',
    ], $tenant);

    expect($ssrfResult['errors'][0])->toContain('not allowed');
});

test('webhook secrets, social tokens, and invitation hashes are hidden from serialization', function (): void {
    $endpoint = WebhookEndpoint::query()->create([
        'name' => 'Secret Test',
        'url' => 'https://example.com/webhook',
        'secret' => 'whsec_test_secret_1234567890',
        'events' => ['user.created'],
        'is_active' => true,
    ]);

    expect($endpoint->toArray())->not->toHaveKey('secret');

    $user = User::factory()->create();
    $social = SocialAccount::query()->create([
        'user_id' => $user->getKey(),
        'provider' => 'github',
        'provider_id' => '12345',
        'connection_scope' => 'portal',
        'access_token' => 'gho_secret_token',
        'refresh_token' => 'ghr_secret_token',
    ]);

    expect($social->toArray())
        ->not->toHaveKey('access_token')
        ->not->toHaveKey('refresh_token');

    $tenant = Tenant::query()->create(['name' => 'Inv Tenant', 'slug' => 'inv-tenant', 'is_active' => true]);
    $app = TenantApplication::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Inv App',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => [],
    ]);
    $env = $app->environments()->create(['environment' => 'development', 'redirect_uris' => [], 'settings' => []]);

    $invitation = ApplicationInvitation::query()->create([
        'tenant_application_environment_id' => $env->getKey(),
        'email' => 'invitee@example.com',
        'role' => 'member',
        'token_hash' => hash('sha256', 'raw-token'),
        'expires_at' => now()->addDay(),
    ]);

    expect($invitation->toArray())->not->toHaveKey('token_hash');
});

test('email code sign-in challenges two-factor authentication when enrolled', function (): void {
    $tenant = Tenant::query()->create(['name' => 'OTP Tenant', 'slug' => 'otp-tenant', 'is_active' => true]);
    $app = TenantApplication::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'OTP App',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://otp.example.com/callback'],
    ]);
    $env = $app->environments()->create([
        'environment' => 'development',
        'redirect_uris' => ['https://otp.example.com/callback'],
        'settings' => ['email_code_sign_in' => true],
    ]);

    $user = User::factory()->forEnvironment($env)->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);

    $record = app(EmailCodeService::class)->send($user, EmailCodePurpose::SignIn);
    expect($record)->not->toBeNull();
});
