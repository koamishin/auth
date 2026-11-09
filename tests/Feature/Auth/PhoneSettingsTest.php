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
function phoneEnvironment(string $slug, array $settings = []): array
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

function phoneFlow(TenantApplication $application): string
{
    $challenge = rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '=');
    $response = test()->get('/oauth/authorize?'.http_build_query([
        'client_id' => $application->oauth_client_id,
        'redirect_uri' => $application->redirect_uris[0],
        'response_type' => 'code',
        'state' => 'phone1234',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'screen_hint' => 'signup',
    ]))->assertOk();

    $flow = $response->viewData('flow');
    expect($flow)->toBeString()->not->toBe('');

    return $flow;
}

test('the authentication page configures the phone number mode', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    [$application, $environment] = phoneEnvironment('phone-settings');

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

    $page()->assertSet('data.phone_mode', FieldMode::Disabled->value);

    $page()->set('data.phone_mode', FieldMode::Optional->value)
        ->call('save', false)
        ->assertHasNoErrors();

    expect($environment->refresh()->phoneMode())->toBe(FieldMode::Optional);

    // The default is not persisted.
    $page()->set('data.phone_mode', FieldMode::Disabled->value)
        ->call('save', false)
        ->assertHasNoErrors();

    expect($environment->refresh()->getSetting('phone_mode'))->toBeNull();
});

test('sign-up collects a phone number when the application allows it', function (): void {
    [$application, $environment] = phoneEnvironment('phone-signup', ['phone_mode' => 'optional']);
    $flow = phoneFlow($application);

    test()->post('/register', [
        'name' => 'Octo Cat',
        'email' => 'octo@example.test',
        'phone_number' => '+14155550123',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
        'flow' => $flow,
    ])->assertRedirect();

    $user = User::query()->where('email', 'octo@example.test')->sole();
    expect($user->phone_number)->toBe('+14155550123');
    expect($user->identity_namespace)->toBe($environment->identityNamespace());

    // Nothing verifies ownership yet, so the number is honest about it.
    expect($user->hasVerifiedPhone())->toBeFalse();
});

test('required phone numbers are demanded at sign-up', function (): void {
    [$application] = phoneEnvironment('phone-required', ['phone_mode' => 'required']);
    $flow = phoneFlow($application);

    test()->post('/register', [
        'name' => 'Octo Cat',
        'email' => 'octo@example.test',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
        'flow' => $flow,
    ])->assertSessionHasErrors('phone_number');

    test()->post('/register', [
        'name' => 'Octo Cat',
        'email' => 'octo@example.test',
        'phone_number' => '+14155550123',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
        'flow' => $flow,
    ])->assertRedirect();

    expect(User::query()->where('email', 'octo@example.test')->sole()->phone_number)->toBe('+14155550123');
});

test('profile updates carry the phone number in international format', function (): void {
    [, $environment] = phoneEnvironment('phone-profile', ['phone_mode' => 'optional']);
    $user = User::factory()->create([
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    test()->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'phone_number' => '+44 20 7946 0958',
    ])->assertSessionHasErrors('phone_number');

    test()->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'phone_number' => '+442079460958',
    ])->assertRedirect(route('profile.edit'));

    expect($user->refresh()->phone_number)->toBe('+442079460958');
});
