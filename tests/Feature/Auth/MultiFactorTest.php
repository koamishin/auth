<?php

use App\Enums\SecondFactor;
use App\Filament\Resources\TenantApplications\Pages\AuthenticationTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\ViewApplicationUser;
use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * @return array{0: Tenant, 1: TenantApplication, 2: TenantApplicationEnvironment, 3: Client}
 */
function mfaContext(string $slug, array $settings = []): array
{
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $client = new Client;
    $client->forceFill([
        'name' => $slug,
        'secret' => 'mfa-secret',
        'redirect_uris' => ['https://'.$slug.'.internal/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ])->save();

    $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
    $application = $tenant->applications()->create([
        'name' => $slug,
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => $client->getAttribute('redirect_uris'),
        'oauth_client_id' => $client->getKey(),
    ]);

    $environment = $application->environments()->create([
        'environment' => 'development',
        'oauth_client_id' => $client->getKey(),
        'redirect_uris' => $client->getAttribute('redirect_uris'),
        'settings' => ['allow_signups' => true, 'oauth_consent_enabled' => true, ...$settings],
    ]);

    return [$tenant, $application, $environment, $client];
}

function mfaMember(TenantApplication $application, TenantApplicationEnvironment $environment, array $attributes = []): User
{
    $user = User::factory()->create([
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
        'password' => Hash::make('member-password'),
        ...$attributes,
    ]);

    $application->users($environment->environment)->attach($user, [
        'role' => 'member',
        'environment' => $environment->environment,
    ]);

    return $user;
}

/**
 * Whether the request was held back for multi-factor enrolment, either by the
 * hosted transition screen or by a redirect to the setup route.
 */
function mfaGateFired(TestResponse $response): bool
{
    if ($response->isRedirect()) {
        return str_contains((string) $response->headers->get('Location'), 'mfa/setup');
    }

    return str_contains($response->getContent(), 'value="mfa"');
}

function mfaAuthorizeUrl(Client $client, string $slug): string
{
    $verifier = str_repeat('e', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return '/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://'.$slug.'.internal/callback',
        'response_type' => 'code',
        'state' => 'mfa',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);
}

/**
 * Start the authorization flow, sign the user in, and follow the flow back to the
 * authorization request, which is where the multi-factor gate runs.
 */
function mfaAuthorizeAs(User $user, Client $client, string $slug, array $session = []): TestResponse
{
    $start = test()->get(mfaAuthorizeUrl($client, $slug))->assertOk();
    $flow = $start->viewData('flow');

    $login = test()->post('/login', [
        'flow' => $flow,
        'email' => $user->email,
        'password' => 'member-password',
    ]);

    $location = $login->headers->get('Location');

    $request = test();

    if ($session !== []) {
        $request = $request->withSession($session);
    }

    return is_string($location) && $location !== ''
        ? $request->get($location)
        : $login;
}

test('a user with no second factor is sent to the setup screen when the application requires it', function (): void {
    [, $application, $environment, $client] = mfaContext('mfa-required', ['require_mfa' => true]);
    $user = mfaMember($application, $environment);

    $response = mfaAuthorizeAs($user, $client, 'mfa-required');

    expect(mfaGateFired($response))->toBeTrue();

    // The setup screen renders the enrolment UI (client side, so assert the page).
    $this->actingAs($user)
        ->get(route('mfa.setup'))
        ->assertOk()
        ->assertSee('MfaSetup', false);

    expect($user->hasEnrolledSecondFactor())->toBeFalse();
});

test('a user with an enrolled second factor is not sent to the setup screen', function (): void {
    [, $application, $environment, $client] = mfaContext('mfa-enrolled', ['require_mfa' => true]);
    $user = mfaMember($application, $environment, ['two_factor_confirmed_at' => now()]);

    $response = mfaAuthorizeAs($user, $client, 'mfa-enrolled');

    expect(mfaGateFired($response))->toBeFalse();
    expect($user->hasEnrolledSecondFactor())->toBeTrue();
});

test('a passkey sign-in satisfies the requirement when passkeys count as multi-factor', function (): void {
    [, $application, $environment, $client] = mfaContext('mfa-passkey', [
        'require_mfa' => true,
        'passkeys_satisfy_mfa' => true,
    ]);
    $user = mfaMember($application, $environment);

    $response = mfaAuthorizeAs($user, $client, 'mfa-passkey', [
        ApplicationAuthentication::MFA_SATISFIED_BY_PASSKEY => true,
    ]);

    expect(mfaGateFired($response))->toBeFalse();
    expect($user->hasEnrolledSecondFactor())->toBeFalse();
});

test('a passkey sign-in does not satisfy the requirement when the application insists on enrolment', function (): void {
    [, $application, $environment, $client] = mfaContext('mfa-passkey-off', [
        'require_mfa' => true,
        'passkeys_satisfy_mfa' => false,
    ]);
    $user = mfaMember($application, $environment);

    $response = mfaAuthorizeAs($user, $client, 'mfa-passkey-off', [
        ApplicationAuthentication::MFA_SATISFIED_BY_PASSKEY => true,
    ]);

    expect(mfaGateFired($response))->toBeTrue();
    expect($user->hasEnrolledSecondFactor())->toBeFalse();
});

test('applications that do not require multi-factor auth are unaffected', function (): void {
    [, $application, $environment, $client] = mfaContext('mfa-off');
    $user = mfaMember($application, $environment);

    $response = mfaAuthorizeAs($user, $client, 'mfa-off');

    expect(mfaGateFired($response))->toBeFalse();
});

test('the environment reports its multi-factor policy', function (): void {
    [, , $environment] = mfaContext('mfa-policy', ['require_mfa' => true, 'passkeys_satisfy_mfa' => false]);

    expect($environment->requiresMultiFactorAuthentication())->toBeTrue();
    expect($environment->passkeysSatisfyMultiFactor())->toBeFalse();

    [, , $defaults] = mfaContext('mfa-policy-defaults');
    expect($defaults->requiresMultiFactorAuthentication())->toBeFalse();
    expect($defaults->passkeysSatisfyMultiFactor())->toBeTrue();
});

test('an administrator can remove a user second factor', function (): void {
    [$tenant, $application, $environment] = mfaContext('mfa-reset');
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $user = mfaMember($application, $environment, [
        'two_factor_secret' => 'secret',
        'two_factor_recovery_codes' => 'codes',
        'two_factor_confirmed_at' => now(),
        'has_email_authentication' => true,
    ]);

    expect($user->enrolledSecondFactors())->toBe([SecondFactor::AuthenticatorApp, SecondFactor::EmailCode]);
    expect($user->hasRecoveryCodes())->toBeTrue();

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    $page = fn () => Livewire::actingAs($admin)->test(ViewApplicationUser::class, [
        'record' => $application->getKey(),
        'userId' => $user->getKey(),
    ])->assertOk();

    $page()->call('removeSecondFactor', SecondFactor::AuthenticatorApp->value)->assertHasNoErrors();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
    expect($user->hasRecoveryCodes())->toBeFalse();
    expect($user->enrolledSecondFactors())->toBe([SecondFactor::EmailCode]);

    // Removing the last factor leaves the account without two-step verification.
    $page()->call('removeSecondFactor', SecondFactor::EmailCode->value)->assertHasNoErrors();

    $user->refresh();
    expect($user->hasEnrolledSecondFactor())->toBeFalse();
    expect($user->has_email_authentication)->toBeFalse();
});

test('removing a factor that is not enrolled is a no-op', function (): void {
    [$tenant, $application, $environment] = mfaContext('mfa-reset-noop');
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $user = mfaMember($application, $environment, ['two_factor_confirmed_at' => now()]);

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)->test(ViewApplicationUser::class, [
        'record' => $application->getKey(),
        'userId' => $user->getKey(),
    ])->call('removeSecondFactor', SecondFactor::EmailCode->value)->assertHasNoErrors();

    expect($user->refresh()->hasEnrolledSecondFactor())->toBeTrue();
});

test('the user profile lists enrolled factors and offers to remove them', function (): void {
    [$tenant, $application, $environment] = mfaContext('mfa-profile');
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $user = mfaMember($application, $environment, [
        'two_factor_confirmed_at' => now(),
        'has_email_authentication' => true,
    ]);

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    $this->get(TenantApplicationResource::getUrl('user', [
        'tenant' => $tenant,
        'record' => $application,
        'userId' => $user->getKey(),
    ]))
        ->assertOk()
        ->assertSee('Two-step verification')
        ->assertSee('Authenticator app')
        ->assertSee('Email code')
        ->assertSee('Remove');
});

test('the authentication page exposes the multi-factor controls', function (): void {
    [$tenant, $application] = mfaContext('mfa-settings');
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    $page = fn () => Livewire::actingAs($admin)->test(
        AuthenticationTenantApplication::class,
        ['record' => $application->getKey()],
    )->assertOk();

    $page()->assertSet('data.require_mfa', false)
        ->assertSet('data.passkeys_satisfy_mfa', true);

    $page()->set('data.require_mfa', true)
        ->set('data.passkeys_satisfy_mfa', false)
        ->call('save', false)
        ->assertHasNoErrors();

    $environment = $application->environments()->where('environment', 'development')->sole()->fresh();
    expect($environment->requiresMultiFactorAuthentication())->toBeTrue();
    expect($environment->passkeysSatisfyMultiFactor())->toBeFalse();

    // Turning the requirement off returns the settings to their defaults.
    $page()->set('data.require_mfa', false)
        ->set('data.passkeys_satisfy_mfa', true)
        ->call('save', false)
        ->assertHasNoErrors();

    $settings = $application->environments()->where('environment', 'development')->sole()->fresh()->settings ?? [];
    expect($settings)->not->toHaveKey('require_mfa');
    expect($settings)->not->toHaveKey('passkeys_satisfy_mfa');
});
