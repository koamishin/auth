<?php

use App\Enums\FieldMode;
use App\Filament\Resources\TenantApplications\Pages\AuthenticationTenantApplication;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * @return array{0: TenantApplication, 1: TenantApplicationEnvironment}
 */
function usernameEnvironment(string $slug, array $settings = []): array
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

function usernameFlow(TenantApplication $application, bool $signup = true): string
{
    $challenge = rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '=');
    $parameters = [
        'client_id' => $application->oauth_client_id,
        'redirect_uri' => $application->redirect_uris[0],
        'response_type' => 'code',
        'state' => 'username123',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];
    if ($signup) {
        $parameters['screen_hint'] = 'signup';
    }

    $response = test()->get('/oauth/authorize?'.http_build_query($parameters))->assertOk();

    $flow = $response->viewData('flow');
    expect($flow)->toBeString()->not->toBe('');

    return $flow;
}

test('the authentication page configures the username mode', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    [$application, $environment] = usernameEnvironment('username-settings');

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    test()->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($application->tenant);
    Filament::bootCurrentPanel();

    $page = fn () => Livewire::actingAs($admin)->test(
        AuthenticationTenantApplication::class,
        ['record' => $application->getKey()],
    )->assertOk();

    $page()->assertSet('data.username_mode', FieldMode::Disabled->value);

    $page()->set('data.username_mode', FieldMode::Required->value)
        ->call('save', false)
        ->assertHasNoErrors();

    expect($environment->refresh()->usernameMode())->toBe(FieldMode::Required);

    // The default is not persisted.
    $page()->set('data.username_mode', FieldMode::Disabled->value)
        ->call('save', false)
        ->assertHasNoErrors();

    expect($environment->refresh()->getSetting('username_mode'))->toBeNull();
});

test('sign-up collects a username when the application allows it', function (): void {
    [$application, $environment] = usernameEnvironment('username-signup', ['username_mode' => 'optional']);
    $flow = usernameFlow($application);

    test()->post('/register', [
        'name' => 'Octo Cat',
        'email' => 'octo@example.test',
        'username' => 'octocat',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
        'flow' => $flow,
    ])->assertRedirect();

    $user = User::query()->where('email', 'octo@example.test')->sole();
    expect($user->username)->toBe('octocat');
    expect($user->identity_namespace)->toBe($environment->identityNamespace());
});

test('required usernames are demanded at sign-up', function (): void {
    [$application] = usernameEnvironment('username-required', ['username_mode' => 'required']);
    $flow = usernameFlow($application);

    test()->post('/register', [
        'name' => 'Octo Cat',
        'email' => 'octo@example.test',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
        'flow' => $flow,
    ])->assertSessionHasErrors('username');

    test()->post('/register', [
        'name' => 'Octo Cat',
        'email' => 'octo@example.test',
        'username' => 'octocat',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
        'flow' => $flow,
    ])->assertRedirect();

    expect(User::query()->where('email', 'octo@example.test')->sole()->username)->toBe('octocat');
});

test('a username works wherever an email address does on the sign-in form', function (): void {
    $user = User::factory()->create(['username' => 'octocat']);

    test()->post(route('login.store'), ['email' => 'octocat', 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('usernames stop working on the sign-in form when the application turns them off', function (): void {
    [$application, $environment] = usernameEnvironment('username-signin-off', ['username_mode' => 'disabled']);
    $user = User::factory()->create([
        'username' => 'octocat',
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $flow = usernameFlow($application, signup: false);

    test()->post(route('login.store'), ['email' => 'octocat', 'password' => 'password', 'flow' => $flow])
        ->assertSessionHasErrors();

    $this->assertGuest();

    // The email address still works: the sign-in is accepted and the flow
    // continues toward the application.
    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'flow' => $flow])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
});

test('profile updates carry the username', function (): void {
    [, $environment] = usernameEnvironment('username-profile', ['username_mode' => 'optional']);
    $user = User::factory()->create([
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    test()->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'username' => 'octocat',
    ])->assertRedirect(route('profile.edit'));

    expect($user->refresh()->username)->toBe('octocat');

    // The format and the namespace uniqueness still apply.
    test()->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'username' => 'no spaces',
    ])->assertSessionHasErrors('username');

    $other = User::factory()->create([
        'username' => 'taken',
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    test()->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'username' => 'taken',
    ])->assertSessionHasErrors('username');

    expect($other->refresh()->username)->toBe('taken');
});
