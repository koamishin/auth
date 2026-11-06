<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\Tenant;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Spatie\Permission\Models\Role;

test('login screen can be rendered', function (): void {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('system admins logging in are redirected to filament admin', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user, 'portal');
    $response->assertRedirect('/admin');
});

test('system admins logging in via inertia are redirected to filament admin', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->withHeader('X-Inertia', 'true')->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user, 'portal');
    expect($response->status())->toBe(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe(url('/admin'));
});

test('system admins logging in with JSON authenticate the portal guard', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $this->postJson(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $this->assertAuthenticatedAs($user, 'portal');
});

test('application users can not access the admin panel', function (): void {
    Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('user');

    $response = $this->actingAs($user)->get('/admin');
    $response->assertForbidden();
});

test('users with legacy fortify two factor columns are challenged', function (): void {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('two-factor.login'));
});

test('users can not authenticate with invalid password', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('home'));
});

test('authentication isolates identical emails between portal and application environments', function (): void {
    $client = new Client;
    $client->forceFill([
        'name' => 'auth-isolate-app',
        'secret' => 'signup-secret',
        'redirect_uris' => ['https://app.internal/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ])->save();

    $tenant = Tenant::query()->create(['name' => 'auth-isolate', 'slug' => 'auth-isolate-'.uniqid(), 'is_active' => true]);
    $application = $tenant->applications()->create([
        'name' => 'auth-isolate-app',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => $client->getAttribute('redirect_uris'),
        'oauth_client_id' => $client->getKey(),
    ]);
    $environment = $application->environments()->create([
        'environment' => 'development',
        'oauth_client_id' => $client->getKey(),
        'redirect_uris' => $client->getAttribute('redirect_uris'),
        'settings' => ['allow_signups' => true, 'oauth_consent_enabled' => true],
    ]);

    $sharedEmail = 'same@isolate.test';
    $portalUser = User::factory()->create([
        'email' => $sharedEmail,
        'password' => Hash::make('portal-password'),
        'identity_namespace' => User::PORTAL_IDENTITY_NAMESPACE,
    ]);

    $appUser = User::factory()->create([
        'email' => $sharedEmail,
        'password' => Hash::make('app-password'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);
    $tenant->users()->attach($appUser, ['role' => 'member']);
    $application->users('development')->attach($appUser, ['role' => 'member', 'environment' => 'development']);

    // 1. Portal login with portal password succeeds
    $this->post(route('login.store'), [
        'email' => $sharedEmail,
        'password' => 'portal-password',
    ])->assertRedirect();
    expect(auth()->id())->toBe($portalUser->id);

    $this->post(route('logout'));
    $this->assertGuest();

    // 2. Portal login with app password fails
    $this->post(route('login.store'), [
        'email' => $sharedEmail,
        'password' => 'app-password',
    ])->assertSessionHasErrors();
    $this->assertGuest();

    $verifier = str_repeat('a', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    // 3. Application flow login with app password succeeds
    $start = $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://app.internal/callback',
        'response_type' => 'code',
        'state' => 'xyz',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();
    $flow = $start->viewData('flow');

    $login = $this->post('/login', [
        'flow' => $flow,
        'email' => $sharedEmail,
        'password' => 'app-password',
    ])->assertRedirect();
    $consent = $this->get((string) $login->headers->get('Location'))->assertOk();
    expect($consent->viewData('page')['props']['user']['id'])->toBe($appUser->id);

    // 4. Application flow login with portal password fails
    $start2 = $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://app.internal/callback',
        'response_type' => 'code',
        'state' => 'xyz2',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();
    $flow2 = $start2->viewData('flow');

    $this->post('/login', [
        'flow' => $flow2,
        'email' => $sharedEmail,
        'password' => 'portal-password',
    ])->assertSessionHasErrors();
});

test('users are rate limited', function (): void {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});

/**
 * Build an application environment with an enrolled user so sign-in policies can
 * be exercised through the hosted OAuth flow.
 *
 * @param  array<string, mixed>  $settings
 * @return array{0: Client, 1: TenantApplicationEnvironment, 2: string}
 */
function signInPolicyEnvironment(string $slug, array $settings): array
{
    $client = new Client;
    $client->forceFill([
        'name' => $slug,
        'secret' => 'signin-secret',
        'redirect_uris' => ['https://'.$slug.'.internal/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ])->save();

    $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);
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
        'settings' => $settings,
    ]);

    $email = $slug.'@signin-policy.test';
    $user = User::factory()->create([
        'email' => $email,
        'password' => Hash::make('app-password'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);
    $tenant->users()->attach($user, ['role' => 'member']);
    $application->users('development')->attach($user, ['role' => 'member', 'environment' => 'development']);

    return [$client, $environment, $email];
}

function signInPolicyFlow(Client $client): string
{
    $verifier = str_repeat('b', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $start = test()->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://'.$client->name.'.internal/callback',
        'response_type' => 'code',
        'state' => 'policy',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    $flow = $start->viewData('flow');

    if (! is_string($flow) || $flow === '') {
        throw new LogicException('The application authentication response did not contain a transaction.');
    }

    return $flow;
}

test('an application can disable email sign-in', function (): void {
    [$client, , $email] = signInPolicyEnvironment('signin-no-email', [
        'allow_signups' => true,
        'auth_email_signin' => false,
    ]);

    $this->post('/login', [
        'flow' => signInPolicyFlow($client),
        'email' => $email,
        'password' => 'app-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('disabling password authentication leaves existing passwords working', function (): void {
    // Matches Clerk: turning password authentication off stops new passwords from
    // being created and leaves established passwords able to sign in.
    [$client, $environment, $email] = signInPolicyEnvironment('signin-no-password', [
        'allow_signups' => true,
        'auth_password_enabled' => false,
        'oauth_consent_enabled' => true,
    ]);

    $user = User::query()->where('email', $email)->sole();

    $login = $this->post('/login', [
        'flow' => signInPolicyFlow($client),
        'email' => $email,
        'password' => 'app-password',
    ])->assertRedirect();

    $consent = $this->get((string) $login->headers->get('Location'))->assertOk();
    expect($consent->viewData('page')['props']['user']['id'])->toBe($user->id);

    // New passwords cannot be created while the setting is off.
    expect($environment->fresh()->allowsPasswordAuthentication())->toBeFalse();
    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'Brand-New-Password-1!',
        'password_confirmation' => 'Brand-New-Password-1!',
    ]))->toThrow(ValidationException::class);
});

test('an application with email and password sign-in enabled still authenticates', function (): void {
    [$client, , $email] = signInPolicyEnvironment('signin-allowed', [
        'allow_signups' => true,
        'oauth_consent_enabled' => true,
    ]);

    $user = User::query()->where('email', $email)->sole();

    $login = $this->post('/login', [
        'flow' => signInPolicyFlow($client),
        'email' => $email,
        'password' => 'app-password',
    ])->assertRedirect();

    // The hosted flow signs the user into the application's own guard.
    $consent = $this->get((string) $login->headers->get('Location'))->assertOk();
    expect($consent->viewData('page')['props']['user']['id'])->toBe($user->id);
});
