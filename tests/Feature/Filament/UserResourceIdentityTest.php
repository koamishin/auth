<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function userResourceIdentityAdmin(Tenant $tenant): User
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

test('editing an application user scopes unique profile fields to the record namespace', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Users', 'slug' => 'user-form']);
    $application = $tenant->applications()->create(['name' => 'App', 'environment' => 'development', 'redirect_uris' => []]);
    $environment = $application->getEnvironmentConfig();
    User::factory()->create(['email' => 'same@example.test', 'username' => 'same', 'phone_number' => '5551234567']);
    $appUser = User::factory()->create();
    $appUser->forceFill(['identity_namespace' => $environment->identityNamespace()])->save();
    $admin = userResourceIdentityAdmin($tenant);
    $fields = ['name' => 'App user', 'email' => 'same@example.test', 'username' => 'same', 'phone_number' => '5551234567'];

    Livewire::actingAs($admin)->test(EditUser::class, ['record' => $appUser->getKey()])->fillForm($fields)->call('save')->assertHasNoFormErrors();

    expect($appUser->fresh()->email)->toBe('same@example.test');
    $duplicate = User::factory()->create();
    $duplicate->forceFill(['identity_namespace' => $environment->identityNamespace()])->save();
    Livewire::actingAs($admin)->test(EditUser::class, ['record' => $duplicate->getKey()])->fillForm($fields)->call('save')
        ->assertHasFormErrors(['email' => 'unique', 'username' => 'unique', 'phone_number' => 'unique']);
});

test('application user directory excludes portal identities and cannot create portal users', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Users', 'slug' => 'user-form-create']);
    $application = $tenant->applications()->create(['name' => 'App', 'environment' => 'development', 'redirect_uris' => []]);
    $portal = User::factory()->create();
    $appUser = User::factory()->create();
    $appUser->forceFill(['identity_namespace' => $application->getEnvironmentConfig()->identityNamespace()])->save();
    userResourceIdentityAdmin($tenant);

    expect(UserResource::getEloquentQuery()->whereKey($portal->id)->exists())->toBeFalse();
    expect(UserResource::getEloquentQuery()->whereKey($appUser->id)->exists())->toBeTrue();
    $this->get(UserResource::getUrl('create', ['tenant' => $tenant]))->assertForbidden();
});
