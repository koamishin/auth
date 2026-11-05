<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function dashboardIdentityAdmin(Tenant $tenant): User
{
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    test()->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    return $admin;
}

test('quick add creates an independent application identity and rejects a duplicate in that environment', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Dashboard', 'slug' => 'dashboard-identity']);
    $application = $tenant->applications()->create(['name' => 'App', 'environment' => 'development', 'redirect_uris' => []]);
    $environment = $application->getEnvironmentConfig();
    $portal = User::factory()->create(['email' => 'same@example.test', 'name' => 'Portal user']);
    $admin = dashboardIdentityAdmin($tenant);
    $data = ['name' => 'App user', 'email' => $portal->email, 'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'user'];

    Livewire::actingAs($admin)->test(Dashboard::class)->callAction('createUser', $data)->assertHasNoActionErrors();

    $appUser = User::query()->where('identity_namespace', $environment->identityNamespace())->where('email', $portal->email)->firstOrFail();
    expect($appUser->id)->not->toBe($portal->id);
    expect(Hash::check('password123', $appUser->password))->toBeTrue();
    expect($portal->fresh()->name)->toBe('Portal user');
    expect($application->users('development')->whereKey($appUser->id)->exists())->toBeTrue();
    expect($application->users('development')->whereKey($portal->id)->exists())->toBeFalse();
    Livewire::actingAs($admin)->test(Dashboard::class)->callAction('createUser', $data)->assertHasActionErrors(['email' => 'unique']);
});

test('quick add without an application creates a portal identity despite an application email match', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Portal', 'slug' => 'dashboard-portal']);
    $otherTenant = Tenant::query()->create(['name' => 'Other', 'slug' => 'dashboard-other']);
    $application = $otherTenant->applications()->create(['name' => 'App', 'environment' => 'development', 'redirect_uris' => []]);
    $appUser = User::factory()->create(['email' => 'same@example.test']);
    $appUser->forceFill(['identity_namespace' => $application->getEnvironmentConfig()->identityNamespace()])->save();
    $admin = dashboardIdentityAdmin($tenant);

    Livewire::actingAs($admin)->test(Dashboard::class)->callAction('createUser', [
        'name' => 'Portal user', 'email' => $appUser->email, 'password' => 'password123',
        'password_confirmation' => 'password123', 'role' => 'user',
    ])->assertHasNoActionErrors();

    $portal = User::query()->where('identity_namespace', User::PORTAL_IDENTITY_NAMESPACE)->where('email', $appUser->email)->firstOrFail();
    expect($portal->id)->not->toBe($appUser->id);
    expect($tenant->users()->whereKey($portal->id)->exists())->toBeTrue();
});

test('dashboard actions cannot update a user from a different namespace even with a legacy membership', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Dashboard', 'slug' => 'dashboard-scope']);
    $application = $tenant->applications()->create(['name' => 'App', 'environment' => 'development', 'redirect_uris' => []]);
    $environment = $application->getEnvironmentConfig();
    $portal = User::factory()->unverified()->create();
    $local = User::factory()->unverified()->create();
    $local->forceFill(['identity_namespace' => $environment->identityNamespace()])->save();
    $application->users()->attach([$portal->id => ['environment' => 'development'], $local->id => ['environment' => 'development']]);
    $admin = dashboardIdentityAdmin($tenant);

    $page = Livewire::actingAs($admin)->test(Dashboard::class);
    $page->call('verifyUserEmail', $portal->id)->call('banUser', $portal->id);
    $page->call('verifyUserEmail', $local->id);

    expect($portal->fresh()->email_verified_at)->toBeNull();
    expect($portal->fresh()->banned_at)->toBeNull();
    expect($local->fresh()->email_verified_at)->not->toBeNull();
});
