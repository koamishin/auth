<?php

use App\Filament\Resources\SystemUsers\SystemUserResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->tenant = Tenant::firstOrCreate(['slug' => 'default'], ['name' => 'Default Organization', 'is_active' => true]);
});

test('admin panel uses native sidebar groups instead of drilldowns', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->hasPlugin('drilldown-sidebar'))->toBeFalse();
});

test('admin navigation sidebar is focused on application management', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->tenant);

    $navGroups = collect(Filament::getNavigation())
        ->map(fn ($group) => $group->getLabel())
        ->filter()
        ->values()
        ->all();

    expect($navGroups)->toEqualCanonicalizing(['Applications', 'Directory & Access']);
});

test('system settings and tools are accessible via user menu items', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->tenant);

    $panel = Filament::getPanel('admin');
    $menuItems = collect($panel->getUserMenuItems())
        ->map(fn ($action) => $action->getLabel())
        ->values()
        ->all();

    expect($menuItems)->toContain(
        'My profile',
        'System Settings',
        'System Users',
        'Roles & Permissions',
        'Activity Logs',
        'Feature Flags',
        'Organizations',
    );
});

test('application users directory strictly excludes system administrators', function (): void {
    $admin = User::factory()->create(['email' => 'sysadmin@test.com']);
    $admin->assignRole('super_admin');

    $appUser = User::factory()->create(['email' => 'enduser@test.com', 'identity_namespace' => 'environment:1']);
    $role = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
    $appUser->assignRole($role);

    $appUserResults = UserResource::getEloquentQuery()->pluck('email')->all();
    expect($appUserResults)->toContain('enduser@test.com');
    expect($appUserResults)->not->toContain('sysadmin@test.com');

    $systemUserResults = SystemUserResource::getEloquentQuery()->pluck('email')->all();
    expect($systemUserResults)->toContain('sysadmin@test.com');
    expect($systemUserResults)->not->toContain('enduser@test.com');
});
