<?php

declare(strict_types=1);

use App\Filament\Pages\Tenancy\RegisterTenant;
use App\Filament\Resources\TenantApplications\Pages\AppearanceTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\AuthenticationTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\CreateTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\EditTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\ListTenantApplications;
use App\Filament\Resources\TenantApplications\Pages\SocialConnectionsTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\ViewTenantApplication;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Services\OAuth\ApplicationProvisioner;
use App\Settings\ApplicationFeaturesSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

test('admin can create an organization tenant and applications', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $tenant = Tenant::create([
        'name' => 'Koamishin Global',
        'slug' => 'koamishin-global',
        'domain' => 'global.koamishin.com',
        'is_active' => true,
    ]);

    expect($tenant->id)->not->toBeNull();

    $this->actingAs($admin);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    // Create the application before choosing its connection.
    Livewire::actingAs($admin)
        ->test(CreateTenantApplication::class)
        ->fillForm([
            'name' => 'Marketing WebApp',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $app = TenantApplication::where('name', 'Marketing WebApp')->first();
    expect($app)->not->toBeNull()
        ->framework->toBeNull()
        ->environment->toBe('development')
        ->oauth_client_id->toBeNull();

    Livewire::actingAs($admin)
        ->test(ViewTenantApplication::class, ['record' => $app->getKey()])
        ->callAction('connectApplication', [
            'framework' => 'nextjs',
            'client_type' => 'public',
            'redirect_uris' => ['http://localhost:3000/api/auth/callback'],
        ])
        ->assertHasNoActionErrors();
    $app->refresh();

    $client = Client::find($app->oauth_client_id);
    expect($client)->not->toBeNull()
        ->name->toBe('Marketing WebApp (Development)')
        ->revoked->toBeFalse();

    // Check generated setup guides
    expect($app->getInstallCommand())->toBe('npm install @koamishin/auth-js');
    expect($app->getEnvSnippet())->toContain('NEXT_PUBLIC_KOAMISHIN_PUBLISHABLE_KEY='.$app->getPublishableKey());
    expect($app->getSetupGuideCode())->toContain("import { createKoamishinClient } from '@koamishin/auth-js';");
});

test('tenant application setup guide generates laravel sdk instructions', function (): void {
    $tenant = Tenant::create([
        'name' => 'Acme Labs',
        'slug' => 'acme-labs',
    ]);

    $client = new Client;
    $client->name = 'Acme Backend API';
    $client->secret = 'testsecret123';
    $client->redirect_uris = ['https://api.acme.com/koamishin/callback'];
    $client->grant_types = ['authorization_code', 'refresh_token'];
    $client->revoked = false;
    $client->save();

    $app = TenantApplication::create([
        'tenant_id' => $tenant->id,
        'oauth_client_id' => $client->id,
        'name' => 'Acme Backend API',
        'framework' => 'laravel',
        'environment' => 'production',
        'redirect_uris' => ['https://api.acme.com/koamishin/callback'],
    ]);

    expect($app->getInstallCommand())->toBe('composer require koamishin/auth-php');
    expect($app->getEnvSnippet())->toContain('KOAMISHIN_PUBLISHABLE_KEY='.$app->getPublishableKey());
    expect($app->getEnvSnippet())->toContain('KOAMISHIN_APPLICATION_ID='.$app->id);
    expect($app->getSetupGuideCode())->toContain('Koamishin::getLoginUrl(');
    expect($app->getSetupGuideCode())->toContain("->middleware('auth:koamishin')");
    expect($app->getSetupGuideCode())->toContain('Koamishin::consumeReturnTo(');
});

test('provisioning creates independent confidential clients for both environments', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Provision org', 'slug' => 'provision-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Backend',
        'framework' => 'laravel',
        'environment' => 'production',
        'redirect_uris' => ['https://backend.example.test/auth/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->firstOrFail();
    $production = $application->environments()->where('environment', 'production')->firstOrFail();
    $credentials = session('new_app_credentials');

    expect($development->oauth_client_id)->not->toBe($production->oauth_client_id);
    expect($application->oauth_client_id)->toBe($production->oauth_client_id);
    expect($development->oauthClient->getAttribute('grant_types'))->toContain('authorization_code', 'refresh_token');
    expect($production->oauthClient->getAttribute('redirect_uris'))->toBe(['https://backend.example.test/auth/callback']);
    expect(Hash::check($credentials['production']['secret'], $production->oauthClient->getAttribute('secret')))->toBeTrue();
    expect($credentials['production']['secret'])->not->toBe($credentials['development']['secret']);
    expect($development->getSetting('allow_signups'))->toBeTrue();
    expect($production->getSetting('allow_signups'))->toBeFalse();
});

test('provisioning respects an explicit self-service signup policy for the selected environment', function (string $environment, bool $allowSignups): void {
    $tenant = Tenant::query()->create(['name' => 'Policy org', 'slug' => 'policy-org']);

    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Policy app',
        'framework' => 'laravel',
        'environment' => $environment,
        'redirect_uris' => ['https://app.example.test/callback'],
        'allow_signups' => $allowSignups,
    ]);

    expect($application->environments()->where('environment', $environment)->sole()->getSetting('allow_signups'))->toBe($allowSignups);
})->with([
    'closed development' => ['development', false],
    'open production' => ['production', true],
]);

test('editing an application saves signup policy only for the selected environment and preserves other settings', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Edit policy org', 'slug' => 'edit-policy-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Edit policy app',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();
    $development->update(['settings' => ['custom_setting' => 'development']]);
    $production->update(['settings' => ['allow_signups' => true, 'custom_setting' => 'production']]);
    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)
        ->test(EditTenantApplication::class, ['record' => $application->getKey()])
        ->assertSetStrict('data.allow_signups', false)
        ->set('data.environment', 'production')
        ->assertSetStrict('data.allow_signups', true)
        ->set('data.allow_signups', false)
        ->set('data.redirect_uris', ['https://app.example.test/callback'])
        ->call('save')
        ->assertHasNoErrors();

    expect($development->fresh()->settings)->toBe(['custom_setting' => 'development']);
    expect($production->fresh()->settings)->toBe(['allow_signups' => false, 'custom_setting' => 'production']);
});

test('application settings keep provider secrets encrypted and scoped to the selected environment', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Settings org', 'slug' => 'settings-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Settings app', 'framework' => 'laravel', 'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();
    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)->test(EditTenantApplication::class, ['record' => $application->getKey()])
        ->set('data.sso_enabled', true)
        ->call('save', false)
        ->assertHasNoErrors();

    expect($development->fresh()->settings)->toMatchArray(['sso_enabled' => true]);
    expect($production->fresh()->getSetting('sso_enabled', false))->toBeFalse();
});

test('social connections are configured on a dedicated page and keep provider secrets encrypted', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Connections org', 'slug' => 'connections-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Connections app', 'framework' => 'laravel', 'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();
    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)->test(SocialConnectionsTenantApplication::class, ['record' => $application->getKey()])
        ->assertSee('id="edit-social-connections-form"', false)
        ->assertSee('form="edit-social-connections-form"', false)
        ->assertSet('data.social_github_mode', 'inherit')
        ->set('data.social_github_mode', 'custom')
        ->set('data.social_github_client_id', 'billing-client')
        ->set('data.social_github_client_secret', 'billing-secret')
        ->set('data.social_google_mode', 'disabled')
        ->call('save', false)
        ->assertHasNoErrors()
        ->assertSet('data.social_github_client_secret', null);

    $settings = $development->fresh()->settings;
    expect($settings['social_providers']['github']['client_id'])->toBe('billing-client');
    expect($settings['social_providers']['github']['client_secret'])->not->toBe('billing-secret');
    expect(Crypt::decryptString($settings['social_providers']['github']['client_secret']))->toBe('billing-secret');
    expect(json_encode($settings))->not->toContain('billing-secret');
    expect($settings['social_providers']['google'])->toBe(['mode' => 'disabled']);
    expect($production->fresh()->settings ?? [])->not->toHaveKey('social_providers');

    // Saving again without re-entering the secret keeps the stored one.
    Livewire::actingAs($admin)->test(SocialConnectionsTenantApplication::class, ['record' => $application->getKey()])
        ->assertSet('data.social_github_client_secret', null)
        ->call('save', false)
        ->assertHasNoErrors();
    expect(Crypt::decryptString($development->fresh()->settings['social_providers']['github']['client_secret']))->toBe('billing-secret');

    // Changing the client ID without a new secret is rejected.
    Livewire::actingAs($admin)->test(SocialConnectionsTenantApplication::class, ['record' => $application->getKey()])
        ->set('data.social_github_client_id', 'changed-client')
        ->call('save', false)
        ->assertHasErrors();
    expect($development->fresh()->settings['social_providers']['github']['client_id'])->toBe('billing-client');

    // Returning a provider to the Portal connection removes the override.
    Livewire::actingAs($admin)->test(SocialConnectionsTenantApplication::class, ['record' => $application->getKey()])
        ->set('data.social_github_mode', 'inherit')
        ->call('save', false)
        ->assertHasNoErrors();
    expect($development->fresh()->settings['social_providers'])->toBe(['google' => ['mode' => 'disabled']]);
});

test('application email verification can be required, disabled, or inherited per environment', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Verification policy org', 'slug' => 'verification-policy-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Verification policy app', 'framework' => 'laravel', 'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();
    $portalSettings = app(ApplicationFeaturesSettings::class);
    $portalSettings->email_verification_required = true;
    app()->instance(ApplicationFeaturesSettings::class, $portalSettings);
    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)->test(AuthenticationTenantApplication::class, ['record' => $application->getKey()])
        ->assertSet('data.email_verification_policy', 'inherit')
        ->set('data.email_verification_policy', 'disabled')
        ->call('save', false)
        ->assertHasNoErrors();

    expect($development->fresh()->getSetting('email_verification_required'))->toBeFalse();
    expect($development->fresh()->requiresEmailVerification())->toBeFalse();
    expect($production->fresh()->requiresEmailVerification())->toBeTrue();
    Livewire::actingAs($admin)->test(AuthenticationTenantApplication::class, ['record' => $application->getKey()])
        ->assertSet('data.email_verification_policy', 'disabled')
        ->set('data.environment', 'production')
        ->assertSet('data.email_verification_policy', 'inherit');

    Livewire::actingAs($admin)->test(AuthenticationTenantApplication::class, ['record' => $application->getKey()])
        ->assertSet('data.email_verification_policy', 'disabled')
        ->set('data.email_verification_policy', 'required')
        ->call('save', false)
        ->assertHasNoErrors();
    expect($development->fresh()->getSetting('email_verification_required'))->toBeTrue();

    Livewire::actingAs($admin)->test(AuthenticationTenantApplication::class, ['record' => $application->getKey()])
        ->assertSet('data.email_verification_policy', 'required')
        ->set('data.email_verification_policy', 'inherit')
        ->call('save', false)
        ->assertHasNoErrors();
    expect(array_key_exists('email_verification_required', $development->fresh()->settings ?? []))->toBeFalse();
    expect($development->fresh()->requiresEmailVerification())->toBeTrue();
});

test('authentication settings are configured on a dedicated page and scope per environment', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Auth org', 'slug' => 'auth-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Auth app', 'framework' => 'laravel', 'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();

    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)->test(AuthenticationTenantApplication::class, ['record' => $application->getKey()])
        ->assertSee('id="edit-authentication-form"', false)
        ->assertSee('form="edit-authentication-form"', false)
        ->assertSet('data.auth_email_signup', true)
        ->assertSet('data.auth_email_signin', true)
        ->assertSet('data.auth_password_enabled', true)
        ->assertSet('data.passkeys_enabled', true)
        ->set('data.auth_email_signup', false)
        ->set('data.auth_email_signin', false)
        ->set('data.auth_password_enabled', false)
        ->set('data.auth_password_min_length', 12)
        ->set('data.passkeys_enabled', false)
        ->set('data.passkey_platform_authenticators', false)
        ->set('data.passkey_require_user_verification', false)
        ->set('data.user_metadata_attributes', [
            ['key' => 'plan', 'type' => 'string', 'visibility' => 'public'],
            ['key' => 'seats', 'type' => 'number', 'visibility' => 'private'],
        ])
        ->set('data.user_metadata_defaults', ['plan' => 'free'])
        ->call('save', false)
        ->assertHasNoErrors();

    expect($development->fresh()->settings)->toMatchArray([
        'auth_email_signup' => false,
        'auth_email_signin' => false,
        'auth_password_enabled' => false,
        'auth_password_min_length' => 12,
        'passkeys_enabled' => false,
        'passkey_platform_authenticators' => false,
        'passkey_require_user_verification' => false,
        'user_metadata_attributes' => [
            ['key' => 'plan', 'type' => 'string', 'visibility' => 'public'],
            ['key' => 'seats', 'type' => 'number', 'visibility' => 'private'],
        ],
        'user_metadata_defaults' => ['plan' => 'free'],
    ]);

    $environment = $development->fresh();
    expect($environment->allowsEmailSignup())->toBeFalse();
    expect($environment->allowsEmailSignin())->toBeFalse();
    expect($environment->allowsPasswordAuthentication())->toBeFalse();
    expect($environment->allowsPasskeys())->toBeFalse();
    expect($environment->allowsPlatformAuthenticators())->toBeFalse();
    expect($environment->requiresPasskeyUserVerification())->toBeFalse();
    expect($environment->userMetadataAttributes())->toHaveCount(2);

    // The other environment is untouched.
    expect($production->fresh()->allowsEmailSignup())->toBeTrue();
    expect($production->fresh()->allowsPasswordAuthentication())->toBeTrue();
    expect($production->fresh()->userMetadataAttributes())->toBe([]);

    // Defaults are not persisted, so the settings stay minimal.
    Livewire::actingAs($admin)->test(AuthenticationTenantApplication::class, ['record' => $application->getKey()])
        ->set('data.auth_email_signup', true)
        ->set('data.auth_email_signin', true)
        ->set('data.auth_password_enabled', true)
        ->set('data.auth_password_min_length', 8)
        ->set('data.passkeys_enabled', true)
        ->set('data.passkey_platform_authenticators', true)
        ->set('data.passkey_require_user_verification', true)
        ->set('data.user_metadata_attributes', [])
        ->set('data.user_metadata_defaults', [])
        ->call('save', false)
        ->assertHasNoErrors();

    expect($development->fresh()->settings ?? [])->not->toHaveKey('auth_email_signup');
    expect($development->fresh()->settings ?? [])->not->toHaveKey('auth_password_min_length');
    expect($development->fresh()->settings ?? [])->not->toHaveKey('user_metadata_attributes');
});

test('authentication metadata attributes reject invalid definitions', function (array $attributes, string $message): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Auth org', 'slug' => 'auth-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Auth app', 'framework' => 'laravel', 'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);

    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)->test(AuthenticationTenantApplication::class, ['record' => $application->getKey()])
        ->set('data.user_metadata_attributes', $attributes)
        ->call('save', false)
        ->assertHasErrors();

    expect($application->environments()->where('environment', 'development')->sole()->fresh()->settings ?? [])
        ->not->toHaveKey('user_metadata_attributes');
})->with([
    'duplicate key' => [
        [['key' => 'plan', 'type' => 'string', 'visibility' => 'public'], ['key' => 'plan', 'type' => 'number', 'visibility' => 'private']],
        'duplicate',
    ],
    'invalid type' => [
        [['key' => 'plan', 'type' => 'object', 'visibility' => 'public']],
        'type',
    ],
    'invalid visibility' => [
        [['key' => 'plan', 'type' => 'string', 'visibility' => 'secret']],
        'visibility',
    ],
]);

test('hosted appearance settings are configured on a dedicated page per environment', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Appearance org', 'slug' => 'appearance-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Appearance app', 'framework' => 'laravel', 'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();
    $production->update(['settings' => ['auth_color_theme' => 'ocean', 'custom_setting' => 'keep']]);

    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)->test(AppearanceTenantApplication::class, ['record' => $application->getKey()])
        ->assertSee('id="edit-appearance-form"', false)
        ->assertSee('form="edit-appearance-form"', false)
        ->assertSet('data.auth_color_theme', 'inherit')
        ->assertSet('data.auth_layout', 'inherit')
        ->assertSet('data.auth_background', 'inherit')
        ->set('data.login_title', 'Welcome to Billing')
        ->set('data.login_description', 'Sign in to your workspace')
        ->set('data.login_logo_url', 'https://example.com/logo.svg')
        ->set('data.auth_layout', 'split')
        ->set('data.auth_color_theme', 'rose')
        ->set('data.auth_background', 'spotlight')
        ->call('save', false)
        ->assertHasNoErrors();

    expect($development->fresh()->settings)->toMatchArray([
        'login_title' => 'Welcome to Billing',
        'login_description' => 'Sign in to your workspace',
        'login_logo_url' => 'https://example.com/logo.svg',
        'auth_layout' => 'split',
        'auth_color_theme' => 'rose',
        'auth_background' => 'spotlight',
    ]);
    expect($production->fresh()->settings)->toBe(['auth_color_theme' => 'ocean', 'custom_setting' => 'keep']);

    Livewire::actingAs($admin)->test(AppearanceTenantApplication::class, ['record' => $application->getKey()])
        ->assertSet('data.auth_color_theme', 'rose')
        ->set('data.environment', 'production')
        ->assertSet('data.auth_color_theme', 'ocean')
        ->set('data.auth_color_theme', 'not-a-theme')
        ->call('save', false)
        ->assertHasErrors();
    expect($production->fresh()->settings)->toBe(['auth_color_theme' => 'ocean', 'custom_setting' => 'keep']);

    Livewire::actingAs($admin)->test(AppearanceTenantApplication::class, ['record' => $application->getKey()])
        ->set('data.login_title', ' ')
        ->set('data.login_description', ' ')
        ->set('data.login_logo_url', '')
        ->set('data.auth_layout', 'inherit')
        ->set('data.auth_color_theme', 'inherit')
        ->set('data.auth_background', 'inherit')
        ->call('save', false)
        ->assertHasNoErrors();
    expect($development->fresh()->settings)->toBe(['allow_signups' => true]);
    expect($production->fresh()->getSetting('auth_color_theme'))->toBe('ocean');
});

test('hosted appearance preview renders the configured sign-in screen', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Preview org', 'slug' => 'preview-org']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Preview app', 'framework' => 'laravel', 'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);
    $application->environments()->where('environment', 'production')->sole()
        ->update(['settings' => ['auth_color_theme' => 'ocean']]);

    actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)->test(AppearanceTenantApplication::class, ['record' => $application->getKey()])
        ->set('data.login_title', 'Preview sign-in')
        ->set('data.auth_layout', 'split')
        ->set('data.auth_color_theme', 'rose')
        ->set('data.auth_background', 'spotlight')
        ->call('save', false)
        ->assertHasNoErrors();

    $preview = (fn (string $environment) => $this->actingAs($admin)
        ->get("/admin/preview-org/tenant-applications/{$application->getKey()}/appearance/preview?environment={$environment}")
        ->assertOk());

    $page = $preview('development');
    $page->assertSee('"authLayout":"split"', false);
    $page->assertSee('"authColorTheme":"rose"', false);
    $page->assertSee('"authBackground":"spotlight"', false);
    $page->assertSee('"loginTitle":"Preview sign-in"', false);
    $page->assertSee('"name":"Preview app"', false);
    $page->assertSee('"environment":"development"', false);

    $page = $preview('production');
    $page->assertSee('"authColorTheme":"ocean"', false);
    $page->assertSee('"environment":"production"', false);

    $outsider = User::factory()->create();
    $this->actingAs($outsider)
        ->get("/admin/preview-org/tenant-applications/{$application->getKey()}/appearance/preview")
        ->assertForbidden();
});

test('production provisioning refuses an insecure callback without creating a client', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Production org', 'slug' => 'production-org']);
    $clientsBefore = Client::query()->count();

    expect(fn () => app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Backend',
        'framework' => 'laravel',
        'environment' => 'production',
        'redirect_uris' => ['http://backend.example.test/auth/callback'],
    ]))->toThrow(ValidationException::class);
    expect(Client::query()->count())->toBe($clientsBefore);
    expect($tenant->applications()->count())->toBe(0);
});

test('provisioning rejects malformed application input before creating clients', function (array $overrides): void {
    $tenant = Tenant::query()->create(['name' => 'Invalid org', 'slug' => 'invalid-org']);
    $clientsBefore = Client::query()->count();
    $data = array_replace([
        'name' => 'Backend',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ], $overrides);

    expect(fn () => app(ApplicationProvisioner::class)->create($tenant, $data))
        ->toThrow(ValidationException::class);
    expect(Client::query()->count())->toBe($clientsBefore);
    expect($tenant->applications()->count())->toBe(0);
})->with([
    'non-string framework' => [['framework' => ['laravel']]],
    'non-array redirect list' => [['redirect_uris' => 'http://localhost:8000/callback']],
    'non-string redirect' => [['redirect_uris' => [123]]],
    'non-boolean signup policy' => [['allow_signups' => 'true']],
]);

test('legacy client migration binds only unambiguous active clients without replacing existing bindings', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Legacy org', 'slug' => 'legacy-org']);
    $makeClient = fn (string $name): Client => Client::query()->create([
        'name' => $name,
        'secret' => 'secret',
        'redirect_uris' => ['https://example.test/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ]);
    $makeApplication = fn (string $name, ?Client $client): TenantApplication => $tenant->applications()->create([
        'name' => $name,
        'framework' => 'laravel',
        'environment' => 'production',
        'redirect_uris' => ['https://example.test/callback'],
        'oauth_client_id' => $client?->getKey(),
    ]);

    $missingClient = $makeClient('missing');
    $missing = $makeApplication('missing', $missingClient);
    $unboundClient = $makeClient('unbound');
    $unbound = $makeApplication('unbound', $unboundClient);
    $unbound->environments()->create(['environment' => 'production', 'redirect_uris' => ['https://existing.test/callback']]);
    $sharedClient = $makeClient('shared');
    $sharedOne = $makeApplication('shared one', $sharedClient);
    $sharedTwo = $makeApplication('shared two', $sharedClient);
    $conflictingClient = $makeClient('conflicting');
    $currentClient = $makeClient('current');
    $conflicting = $makeApplication('conflicting', $conflictingClient);
    $conflicting->environments()->create([
        'environment' => 'production', 'oauth_client_id' => $currentClient->getKey(), 'redirect_uris' => [],
    ]);
    $elsewhereClient = $makeClient('elsewhere');
    $elsewhere = $makeApplication('elsewhere', $elsewhereClient);
    $other = $makeApplication('other', null);
    $other->environments()->create([
        'environment' => 'development', 'oauth_client_id' => $elsewhereClient->getKey(), 'redirect_uris' => [],
    ]);
    $revokedClient = $makeClient('revoked');
    $revokedClient->update(['revoked' => true]);
    $revoked = $makeApplication('revoked', $revokedClient);

    $migration = require database_path('migrations/2026_10_28_065707_backfill_legacy_application_environment_clients.php');
    $migration->up();
    $migration->up();

    expect($missing->environments()->sole()->oauth_client_id)->toBe($missingClient->getKey());
    expect($missing->environments()->sole()->redirect_uris)->toBe(['https://example.test/callback']);
    expect($unbound->environments()->sole()->oauth_client_id)->toBe($unboundClient->getKey());
    expect($unbound->environments()->sole()->redirect_uris)->toBe(['https://existing.test/callback']);
    expect($sharedOne->environments()->count())->toBe(0);
    expect($sharedTwo->environments()->count())->toBe(0);
    expect($conflicting->environments()->sole()->oauth_client_id)->toBe($currentClient->getKey());
    expect($elsewhere->environments()->count())->toBe(0);
    expect($revoked->environments()->count())->toBe(0);
});

test('user can register a new tenant organization via filament tenancy registration', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $this->actingAs($user);
    Filament::setCurrentPanel('admin');

    Livewire::actingAs($user)
        ->test(RegisterTenant::class)
        ->fillForm([
            'name' => 'New Organization',
            'slug' => 'new-org',
            'domain' => 'neworg.koamishin.com',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'new-org')->first();
    expect($tenant)->not->toBeNull()
        ->name->toBe('New Organization');

    expect($user->tenants()->whereKey($tenant)->exists())->toBeTrue();
});

test('tenant applications are scoped strictly to the current tenant', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $tenantA = Tenant::create(['name' => 'Org Alpha', 'slug' => 'org-alpha']);
    $tenantB = Tenant::create(['name' => 'Org Beta', 'slug' => 'org-beta']);

    TenantApplication::create([
        'tenant_id' => $tenantA->id,
        'name' => 'Alpha Internal App',
        'framework' => 'nextjs',
        'environment' => 'production',
        'redirect_uris' => ['https://alpha.internal/callback'],
    ]);

    TenantApplication::create([
        'tenant_id' => $tenantB->id,
        'name' => 'Beta Internal App',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);

    $this->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenantA);
    Filament::bootCurrentPanel();

    Livewire::actingAs($user)
        ->test(ListTenantApplications::class)
        ->assertSee('Alpha Internal App')
        ->assertDontSee('Beta Internal App');
});

test('admin can rotate client secret on application view page', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::create(['name' => 'Rotate Org', 'slug' => 'rotate-org', 'is_active' => true]);

    $app = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Backend API',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);

    $client = Client::find($app->oauth_client_id);
    $originalSecret = $client->secret;

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    $test = Livewire::actingAs($admin)
        ->test(ViewTenantApplication::class, ['record' => $app->getKey()])
        ->callAction('regenerateSecret')
        ->assertHasNoActionErrors();

    $client->refresh();
    expect($client->secret)->not->toBe($originalSecret);

    $regen = $test->get('regeneratedSecret');
    expect($regen)->not->toBeNull();
    expect($regen['secret'])->not->toBeEmpty();
    expect(Hash::check($regen['secret'], $client->secret))->toBeTrue();
});
