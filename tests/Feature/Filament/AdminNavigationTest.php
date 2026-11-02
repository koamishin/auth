<?php

use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use OsamaAtef\DrilldownSidebar\DrilldownSidebarPlugin;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->tenant = Tenant::firstOrCreate(['slug' => 'default'], ['name' => 'Default Organization', 'is_active' => true]);
});

test('admin panel has drilldown sidebar plugin registered with application groups', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->hasPlugin('drilldown-sidebar'))->toBeTrue();

    $plugin = $panel->getPlugin('drilldown-sidebar');
    expect($plugin)->toBeInstanceOf(DrilldownSidebarPlugin::class);
    expect($plugin->getDrilledGroups())->toContain('Applications', 'Directory & Access');
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
        'Roles & Permissions',
        'Activity Logs',
        'Feature Flags',
        'Organizations',
    );
});
