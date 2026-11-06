<?php

use App\Actions\Fortify\CreateNewUser;
use App\Filament\Resources\TenantApplications\Pages\AuthenticationTenantApplication;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * @return array{0: TenantApplication, 1: TenantApplicationEnvironment}
 */
function userModelEnvironment(string $slug, array $settings = []): array
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

function bindUserModelEnvironment(?TenantApplicationEnvironment $environment): void
{
    request()->attributes->set('koamishin.application_environment', $environment);
}

test('name parts are optional and off by default', function (): void {
    [, $environment] = userModelEnvironment('name-parts-default');

    expect($environment->collectsNameParts())->toBeFalse();
    expect($environment->allowsAccountDeletion())->toBeTrue();

    $environment->update(['settings' => [...$environment->settings, 'allow_name_parts' => true, 'allow_account_deletion' => false]]);
    $environment = $environment->refresh();

    expect($environment->collectsNameParts())->toBeTrue();
    expect($environment->allowsAccountDeletion())->toBeFalse();
});

test('registration collects a first and last name and keeps the display name in step', function (): void {
    [, $environment] = userModelEnvironment('name-parts-registration', ['allow_name_parts' => true]);
    bindUserModelEnvironment($environment);

    $user = app(CreateNewUser::class)->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@name-parts.test',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
    ]);

    expect($user->first_name)->toBe('Ada');
    expect($user->last_name)->toBe('Lovelace');
    expect($user->name)->toBe('Ada Lovelace');
});

test('registration still works with a single name when parts are not collected', function (): void {
    [, $environment] = userModelEnvironment('name-parts-off');
    bindUserModelEnvironment($environment);

    $user = app(CreateNewUser::class)->create([
        'name' => 'Single Name',
        'email' => 'single@name-parts.test',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
    ]);

    expect($user->name)->toBe('Single Name');
    expect($user->first_name)->toBeNull();
    expect($user->last_name)->toBeNull();
});

test('name parts are only validated when the application collects them', function (): void {
    // Collecting: an over-long part is rejected.
    [, $collecting] = userModelEnvironment('name-parts-validate', ['allow_name_parts' => true]);
    bindUserModelEnvironment($collecting);

    expect(fn () => app(CreateNewUser::class)->create([
        'first_name' => str_repeat('a', 300),
        'last_name' => 'Lovelace',
        'email' => 'too-long@name-parts.test',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
    ]))->toThrow(ValidationException::class);

    // Not collecting: the same payload is simply ignored, and the single name is used.
    [, $ignoring] = userModelEnvironment('name-parts-ignored');
    bindUserModelEnvironment($ignoring);

    $user = app(CreateNewUser::class)->create([
        'name' => 'Single Name',
        'first_name' => str_repeat('a', 300),
        'last_name' => 'Lovelace',
        'email' => 'ignored@name-parts.test',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
    ]);

    expect($user->name)->toBe('Single Name');
});

test('updating a profile keeps the display name in step with the name parts', function (): void {
    [, $environment] = userModelEnvironment('name-parts-profile', ['allow_name_parts' => true]);

    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'profile@name-parts.test',
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->patch(route('profile.update'), [
            'name' => 'Old Name',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'profile@name-parts.test',
        ])
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->first_name)->toBe('Grace');
    expect($user->last_name)->toBe('Hopper');
    expect($user->name)->toBe('Grace Hopper');
});

test('an application can forbid its users from deleting their accounts', function (): void {
    [, $environment] = userModelEnvironment('account-deletion-off', ['allow_account_deletion' => false]);

    $user = User::factory()->create([
        'email' => 'nodelete@account-deletion.test',
        'password' => Hash::make('Password-1234!'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('profile.destroy'), ['password' => 'Password-1234!'])
        ->assertRedirect(route('profile.edit'));

    expect(User::query()->whereKey($user->getKey())->exists())->toBeTrue();
});

test('the authentication page exposes the user permission controls', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    [$application, $environment] = userModelEnvironment('name-parts-settings');

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

    $page()->assertSet('data.allow_name_parts', false)
        ->assertSet('data.allow_account_deletion', true);

    $page()->set('data.allow_name_parts', true)
        ->set('data.allow_account_deletion', false)
        ->call('save', false)
        ->assertHasNoErrors();

    $environment = $environment->refresh();
    expect($environment->collectsNameParts())->toBeTrue();
    expect($environment->allowsAccountDeletion())->toBeFalse();

    // Defaults are not persisted.
    $page()->set('data.allow_name_parts', false)
        ->set('data.allow_account_deletion', true)
        ->call('save', false)
        ->assertHasNoErrors();

    $settings = $environment->refresh()->settings ?? [];
    expect($settings)->not->toHaveKey('allow_name_parts');
    expect($settings)->not->toHaveKey('allow_account_deletion');
});
