<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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
        'settings' => ['allow_signups' => true],
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
