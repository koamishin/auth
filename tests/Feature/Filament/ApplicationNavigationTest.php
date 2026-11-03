<?php

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Support\ApplicationNavigation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

function navigationTenant(string $slug): Tenant
{
    return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
}

function navigationApplication(Tenant $tenant, string $name): TenantApplication
{
    return $tenant->applications()->create(['name' => $name, 'environment' => 'development', 'redirect_uris' => []]);
}

function navigationRoute(string $name, mixed $record = null): void
{
    $route = Route::getRoutes()->getByName($name);
    $route->bind(request());
    if ($record !== null) {
        $route->setParameter('record', $record);
    }
    request()->setRouteResolver(fn () => $route);
}

function navigationAdmin(): User
{
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

test('navigation contains only real application destinations with route based active state', function (): void {
    $tenant = navigationTenant('navigation');
    $application = navigationApplication($tenant, 'Selected');
    $this->actingAs(navigationAdmin());
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    navigationRoute('filament.admin.resources.tenant-applications.edit', (string) $application->id);

    $navigation = ApplicationNavigation::forTenant($tenant);
    $items = collect($navigation['groups'])->flatMap(fn (array $group) => $group['items'])->keyBy('label');

    expect($navigation['application']->is($application))->toBeTrue();
    expect($items->keys()->all())->toBe(['Overview', 'API keys', 'Settings', 'Environment', 'Appearance', 'Authentication features', 'Social connections']);
    expect($items['Overview'])->toBe([
        'label' => 'Overview',
        'url' => TenantApplicationResource::getUrl('view', ['tenant' => $tenant, 'record' => $application]),
        'active' => false,
        'fragment' => null,
    ]);
    expect($items['API keys']['fragment'])->toBe('api-keys');
    expect($items['Environment']['url'])->toBe(TenantApplicationResource::getUrl('edit', ['tenant' => $tenant, 'record' => $application]));
    expect($items['Environment']['active'])->toBeTrue();
    expect($items['Social connections']['fragment'])->toBe('social-connections');
    expect($items->pluck('url')->every(fn (string $url): bool => ! str_contains($url, '#')))->toBeTrue();
});

test('context uses only application record routes and rejects invalid or foreign records without fallback', function (): void {
    $tenant = navigationTenant('navigation');
    $other = navigationTenant('other');
    $selected = navigationApplication($tenant, 'Selected');
    $routed = navigationApplication($tenant, 'Routed');
    $foreign = navigationApplication($other, 'Foreign');
    session(['current_application_id' => $selected->id]);

    navigationRoute('filament.admin.resources.tenant-applications.view', $routed);
    expect(ApplicationNavigation::currentApplication($tenant)->is($routed))->toBeTrue();
    expect(session('current_application_id'))->toBe($routed->id);

    navigationRoute('filament.admin.resources.tenant-applications.edit', (string) $foreign->id);
    expect(ApplicationNavigation::forTenant($tenant))->toBe(['application' => null, 'groups' => []]);
    expect(session('current_application_id'))->toBe($routed->id);

    navigationRoute('filament.admin.resources.tenant-applications.view', '999999999');
    expect(ApplicationNavigation::forTenant($tenant))->toBe(['application' => null, 'groups' => []]);

    navigationRoute('filament.admin.resources.users.view', $selected->id);
    expect(ApplicationNavigation::currentApplication($tenant)->is($routed))->toBeTrue();
    expect(ApplicationNavigation::forTenant(null))->toBe(['application' => null, 'groups' => []]);
});

test('context falls back within the current tenant when the session selection is stale', function (): void {
    $tenant = navigationTenant('navigation');
    $other = navigationTenant('other');
    $foreign = navigationApplication($other, 'Foreign');
    session(['current_application_id' => $foreign->id]);

    expect(ApplicationNavigation::forTenant($tenant))->toBe(['application' => null, 'groups' => []]);

    $application = navigationApplication($tenant, 'Default');
    expect(ApplicationNavigation::currentApplication($tenant)->is($application))->toBeTrue();
    expect(session('current_application_id'))->toBe($application->id);
});

test('navigation applies view and edit permissions independently', function (bool $view, bool $edit, array $labels): void {
    $tenant = navigationTenant('navigation');
    navigationApplication($tenant, 'Selected');
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Gate::before(fn (User $user, string $ability): ?bool => match ($ability) {
        'view' => $view,
        'update' => $edit,
        default => null,
    });

    $groups = ApplicationNavigation::forTenant($tenant)['groups'];

    expect(collect($groups)->pluck('label')->all())->toBe($labels);
})->with([
    'view only' => [true, false, ['Application']],
    'edit only' => [false, true, ['Configuration']],
    'neither' => [false, false, []],
]);

test('view and edit destinations render their fragment targets and switching persists selection', function (): void {
    $tenant = navigationTenant('navigation');
    $first = navigationApplication($tenant, 'First application');
    $second = navigationApplication($tenant, 'Second application');
    $this->actingAs(navigationAdmin());

    $this->withSession(['current_application_id' => $first->id])
        ->post(route('filament.admin.switch-application', ['tenant' => $tenant, 'application' => $second]))
        ->assertRedirect(TenantApplicationResource::getUrl('view', ['tenant' => $tenant, 'record' => $second]))
        ->assertSessionHas('current_application_id', $second->id);

    $this->get(TenantApplicationResource::getUrl('view', ['tenant' => $tenant, 'record' => $second]))
        ->assertOk()
        ->assertSee('id="api-keys"', false)
        ->assertSee('Second application');

    $this->get(TenantApplicationResource::getUrl('edit', ['tenant' => $tenant, 'record' => $second]))
        ->assertOk()
        ->assertSee('id="environment-callbacks"', false)
        ->assertSee('id="hosted-login-appearance"', false)
        ->assertSee('id="application-authentication-features"', false)
        ->assertSee('id="social-connections"', false);
});

test('the rendered sidebar follows the application in a direct record URL', function (): void {
    $tenant = navigationTenant('navigation');
    $selected = navigationApplication($tenant, 'Previously selected');
    $routed = navigationApplication($tenant, 'Opened directly');
    $this->actingAs(navigationAdmin());
    $editUrl = TenantApplicationResource::getUrl('edit', ['tenant' => $tenant, 'record' => $routed]);
    $staleEditUrl = TenantApplicationResource::getUrl('edit', ['tenant' => $tenant, 'record' => $selected]);

    $this->withSession(['current_application_id' => $selected->id])
        ->get(TenantApplicationResource::getUrl('view', ['tenant' => $tenant, 'record' => $routed]))
        ->assertOk()
        ->assertSee('href="'.$editUrl.'#hosted-login-appearance"', false)
        ->assertDontSee('href="'.$staleEditUrl.'#hosted-login-appearance"', false)
        ->assertSessionHas('current_application_id', $routed->id);
});

test('an application record from another tenant returns not found', function (): void {
    $tenant = navigationTenant('navigation');
    $other = navigationTenant('other');
    $foreign = navigationApplication($other, 'Foreign application');
    $this->actingAs(navigationAdmin());

    $this->get(TenantApplicationResource::getUrl('view', ['tenant' => $tenant, 'record' => $foreign]))
        ->assertNotFound();
});
