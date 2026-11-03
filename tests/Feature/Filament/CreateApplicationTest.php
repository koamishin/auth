<?php

use App\Filament\Resources\TenantApplications\Pages\CreateTenantApplication;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->tenant = Tenant::firstOrCreate(['slug' => 'default'], ['name' => 'Default Organization', 'is_active' => true]);
});

test('create application page renders clerk style project type and authentication options', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)
        ->test(CreateTenantApplication::class)
        ->assertSee('Select a project type')
        ->assertSee('Consumer')
        ->assertSee('B2B')
        ->assertSee('Authentication')
        ->assertSee('Sign in options')
        ->assertSee('Sign in to')
        ->assertSee('Secured by')
        ->set('data.project_type', 'b2b')
        ->assertSee('Roles')
        ->assertSee('Manage default roles for members')
        ->set('data.project_type', 'consumer')
        ->fillForm([
            'name' => 'Clerk Style SaaS',
            'framework' => 'laravel',
            'environment' => 'development',
            'redirect_uris' => ['http://localhost:8000/callback'],
            'auth_google' => true,
            'auth_email' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $app = TenantApplication::where('name', 'Clerk Style SaaS')->first();
    expect($app)->not->toBeNull()
        ->and($app->framework)->toBe('laravel');
});
