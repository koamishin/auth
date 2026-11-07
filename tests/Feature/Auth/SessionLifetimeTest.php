<?php

use App\Filament\Resources\TenantApplications\Pages\AuthenticationTenantApplication;
use App\Http\Middleware\EnforceSessionLifetime;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationProvisioner;
use App\Settings\ApplicationSecuritySettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * @return array{0: TenantApplication, 1: TenantApplicationEnvironment}
 */
function sessionEnvironment(string $slug, array $settings = []): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => $slug.' app',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://'.$slug.'.internal/callback'],
    ]);

    $environment = $application->environments()->where('environment', 'development')->sole();

    if ($settings !== []) {
        $environment->update(['settings' => [...$environment->settings, ...$settings]]);
    }

    return [$application, $environment->refresh()];
}

function sessionUser(TenantApplicationEnvironment $environment): User
{
    return User::factory()->create([
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);
}

test('session lifetime settings are read from the environment', function (): void {
    [, $development] = sessionEnvironment('session-defaults');

    // A provisioned environment sets no session lifetime, so the platform default
    // of seven days applies, matching Clerk.
    expect($development->sessionInactivityTimeout())->toBeNull();
    expect($development->sessionMaxLifetime())
        ->toBe(TenantApplicationEnvironment::DEFAULT_SESSION_MAX_LIFETIME)
        ->toBe(10080);

    [, $configured] = sessionEnvironment('session-configured', [
        'session_inactivity_timeout' => 30,
        'session_max_lifetime' => 480,
    ]);

    expect($configured->sessionInactivityTimeout())->toBe(30);
    expect($configured->sessionMaxLifetime())->toBe(480);
});

test('a zero session setting means that limit is disabled', function (): void {
    [, $environment] = sessionEnvironment('session-zero', [
        'session_inactivity_timeout' => 0,
        'session_max_lifetime' => 0,
    ]);

    expect($environment->sessionInactivityTimeout())->toBeNull();
    expect($environment->sessionMaxLifetime())->toBeNull();
});

test('an idle session is ended once its inactivity timeout passes', function (): void {
    [, $environment] = sessionEnvironment('session-idle', [
        'session_inactivity_timeout' => 30,
        'session_max_lifetime' => 0,
    ]);

    $user = sessionUser($environment);

    $this->actingAs($user);
    $this->get(route('dashboard'))->assertOk();

    // The limits are pinned to the session on first use.
    $pinned = session(EnforceSessionLifetime::SESSION_KEY);
    expect($pinned['idle'])->toBe(30);
    expect($pinned['max'])->toBeNull();

    // Activity inside the window keeps the session alive.
    $this->travel(20)->minutes();
    $this->get(route('dashboard'))->assertOk();

    // Going quiet past the window ends it.
    $this->travel(31)->minutes();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('a session is ended once its maximum lifetime passes even while active', function (): void {
    [, $environment] = sessionEnvironment('session-max', [
        'session_inactivity_timeout' => 0,
        'session_max_lifetime' => 60,
    ]);

    $user = sessionUser($environment);

    $this->actingAs($user);
    $this->get(route('dashboard'))->assertOk();

    // Staying active does not extend the maximum lifetime.
    $this->travel(30)->minutes();
    $this->get(route('dashboard'))->assertOk();

    $this->travel(31)->minutes();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('changing the lifetime does not affect a session that is already signed in', function (): void {
    [, $environment] = sessionEnvironment('session-pinned', [
        'session_inactivity_timeout' => 0,
        'session_max_lifetime' => 120,
    ]);

    $user = sessionUser($environment);

    $this->actingAs($user);
    $this->get(route('dashboard'))->assertOk();

    $pinned = session(EnforceSessionLifetime::SESSION_KEY);
    expect($pinned['max'])->toBe(120);

    // An administrator lowers the limit; the running session keeps the old one.
    $environment->update(['settings' => [...$environment->settings, 'session_max_lifetime' => 5]]);
    expect($environment->refresh()->sessionMaxLifetime())->toBe(5);

    $this->travel(10)->minutes();
    $this->get(route('dashboard'))->assertOk();

    expect(session(EnforceSessionLifetime::SESSION_KEY)['max'])->toBe(120);
});

test('a portal session falls back to the organization security setting', function (): void {
    $settings = app(ApplicationSecuritySettings::class);
    $settings->session_lifetime = 45;
    app()->instance(ApplicationSecuritySettings::class, $settings);

    $portalUser = User::factory()->create([
        'identity_namespace' => User::PORTAL_IDENTITY_NAMESPACE,
        'tenant_application_environment_id' => null,
    ]);

    $this->actingAs($portalUser);
    $this->get(route('dashboard'))->assertOk();

    $pinned = session(EnforceSessionLifetime::SESSION_KEY);

    // No inactivity limit, and the portal's own lifetime as the maximum.
    expect($pinned['idle'])->toBeNull();
    expect($pinned['max'])->toBe(45);
});

test('the sessions tab configures the limits and rejects disabling both', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    [$application, $environment] = sessionEnvironment('session-settings');

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($application->tenant);
    Filament::bootCurrentPanel();

    $page = fn () => Livewire::actingAs($admin)->test(
        AuthenticationTenantApplication::class,
        ['record' => $application->getKey()],
    )->assertOk();

    $page()->assertSet('data.session_inactivity_timeout', 0)
        ->assertSet('data.session_max_lifetime', TenantApplicationEnvironment::DEFAULT_SESSION_MAX_LIFETIME);

    $page()->set('data.session_inactivity_timeout', 15)
        ->set('data.session_max_lifetime', 240)
        ->call('save', false)
        ->assertHasNoErrors();

    $environment = $environment->refresh();
    expect($environment->sessionInactivityTimeout())->toBe(15);
    expect($environment->sessionMaxLifetime())->toBe(240);

    // Disabling both would leave sessions that never expire, so it is refused.
    $page()->set('data.session_inactivity_timeout', 0)
        ->set('data.session_max_lifetime', 0)
        ->call('save', false)
        ->assertHasErrors('session_max_lifetime');

    expect($environment->refresh()->sessionMaxLifetime())->toBe(240);
});

test('newly provisioned environments seed settings the code actually reads', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Seeded', 'slug' => 'seeded', 'is_active' => true]);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Seeded app',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://seeded.internal/callback'],
    ]);

    $production = $application->environments()->where('environment', 'production')->sole();
    $development = $application->environments()->where('environment', 'development')->sole();

    // Production is provisioned with an explicit requirement, and that default now
    // takes effect instead of being ignored. Development sets nothing, so it keeps
    // inheriting the portal default.
    expect($production->getSetting('email_verification_required'))->toBeTrue();
    expect($development->getSetting('email_verification_required'))->toBeNull();

    // Multi-factor auth stays opt-in, so a new application does not force every
    // end user to enrol a second factor.
    expect($production->requiresMultiFactorAuthentication())->toBeFalse();
    expect($development->requiresMultiFactorAuthentication())->toBeFalse();

    // Session lifetime is not seeded, so the platform default applies until an
    // administrator chooses otherwise.
    expect($production->sessionMaxLifetime())->toBe(TenantApplicationEnvironment::DEFAULT_SESSION_MAX_LIFETIME);
    expect($development->sessionMaxLifetime())->toBe(TenantApplicationEnvironment::DEFAULT_SESSION_MAX_LIFETIME);
});
