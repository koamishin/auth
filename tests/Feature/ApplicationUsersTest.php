<?php

declare(strict_types=1);

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use Spatie\Permission\Models\Role;

function applicationUsersAdmin(): User
{
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

function applicationUsersTenant(string $slug): Tenant
{
    return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
}

function applicationUsersApplication(Tenant $tenant, string $name): TenantApplication
{
    return $tenant->applications()->create(['name' => $name, 'environment' => 'development', 'redirect_uris' => []]);
}

test('application users are restricted to the selected application and active environment', function (): void {
    $tenant = applicationUsersTenant('users');
    $otherTenant = applicationUsersTenant('other');
    $application = applicationUsersApplication($tenant, 'Selected');
    $otherApplication = applicationUsersApplication($tenant, 'Other app');
    $foreignApplication = applicationUsersApplication($otherTenant, 'Foreign app');
    $development = User::factory()->create(['name' => 'Development member', 'email' => 'development@example.test']);
    $production = User::factory()->create(['name' => 'Production member', 'email' => 'production@example.test']);
    $shared = User::factory()->create(['name' => 'Shared member']);
    $other = User::factory()->create(['name' => 'Other application member']);
    $foreign = User::factory()->create(['name' => 'Foreign application member']);
    $unrelated = User::factory()->create(['name' => 'Unrelated workspace user']);

    $application->users()->attach($development, ['environment' => 'development', 'role' => 'editor', 'created_at' => '2024-03-02 12:00:00']);
    $application->users()->attach($production, ['environment' => 'production', 'role' => 'member']);
    $application->users()->attach($shared, ['environment' => 'development', 'role' => 'member']);
    $application->users()->attach($shared, ['environment' => 'production', 'role' => 'member']);
    $otherApplication->users()->attach($other, ['environment' => 'development']);
    $foreignApplication->users()->attach($foreign, ['environment' => 'development']);
    $tenant->users()->attach($unrelated);
    $this->actingAs(applicationUsersAdmin());

    $url = TenantApplicationResource::getUrl('users', ['tenant' => $tenant, 'record' => $application]);
    $this->get($url)->assertOk()
        ->assertSee('Development member')->assertSee('development@example.test')->assertSee('Shared member')->assertSee('editor')
        ->assertSee('Mar 2, 2024 12:00')
        ->assertDontSee('Production member')->assertDontSee('Other application member')
        ->assertDontSee('Foreign application member')->assertDontSee('Unrelated workspace user');

    $application->update(['environment' => 'production']);
    $response = $this->get($url)->assertOk()
        ->assertSee('Production member')->assertSee('Shared member')
        ->assertDontSee('Development member')->assertDontSee('Other application member')
        ->assertDontSee('Foreign application member')->assertDontSee('Unrelated workspace user');
    expect(substr_count($response->getContent(), 'Shared member'))->toBe(1);
});

test('application and environment switchers retain the selected application context', function (): void {
    $tenant = applicationUsersTenant('switch');
    $first = applicationUsersApplication($tenant, 'First');
    $second = applicationUsersApplication($tenant, 'Second');
    $first->getEnvironmentConfig('production');
    $this->actingAs(applicationUsersAdmin());

    $this->get(TenantApplicationResource::getUrl('users', ['tenant' => $tenant, 'record' => $first]))
        ->assertOk()
        ->assertSee(route('filament.admin.switch-application', ['tenant' => $tenant, 'application' => $second]))
        ->assertSee(route('filament.admin.toggle-environment', ['tenant' => $tenant, 'application' => $first, 'environment' => 'production']));

    $this->post(route('filament.admin.toggle-environment', ['tenant' => $tenant, 'application' => $first, 'environment' => 'production']))
        ->assertRedirect(TenantApplicationResource::getUrl('view', ['tenant' => $tenant, 'record' => $first]));
    expect($first->fresh()->environment)->toBe('production');

    $this->post(route('filament.admin.switch-application', ['tenant' => $tenant, 'application' => $second]))
        ->assertRedirect(TenantApplicationResource::getUrl('view', ['tenant' => $tenant, 'record' => $second]))
        ->assertSessionHas('current_application_id', $second->id);
});

test('an empty application environment displays an enrollment empty state', function (): void {
    $tenant = applicationUsersTenant('empty');
    $application = applicationUsersApplication($tenant, 'Empty app');
    $this->actingAs(applicationUsersAdmin());

    $this->get(TenantApplicationResource::getUrl('users', ['tenant' => $tenant, 'record' => $application]))
        ->assertOk()
        ->assertSee('No users enrolled')
        ->assertSee('Users enrolled in this application and environment will appear here.');
});
