<?php

use App\Filament\Resources\OAuthClients\OAuthClientResource;
use App\Filament\Resources\TenantApplications\Pages\ListApplicationUsers;
use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Filament\Resources\Webhooks\WebhookEndpointResource;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Support\ApplicationNavigation;
use Filament\Facades\Filament;
use Filament\Livewire\Sidebar;
use Filament\Navigation\NavigationGroup;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
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

function navigationSidebar(TestResponse $response): string
{
    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $sidebarRoot = $document->getElementById('fi-main-sidebar');
    expect($sidebarRoot)->not->toBeNull();
    expect($sidebarRoot->getAttribute('x-data'))->toStartWith('filamentSidebar(');
    expect($sidebarRoot->getAttribute('x-bind:inert'))->toBe('isHidden');
    expect($sidebarRoot->getAttribute('x-effect'))->toBe('focusInitialNavigation()');
    expect($sidebarRoot->getAttribute('x-on:keydown.tab'))->toBe('handleTab($event)');
    expect($sidebarRoot->getAttribute('x-on:keydown.escape.document'))->toBe('handleEscape($event)');

    $sidebars = new DOMXPath($document)->query('//*[@id="fi-main-sidebar"]//nav[@class="fi-sidebar-nav"]');
    expect($sidebars)->toHaveCount(1);
    expect($sidebars->item(0)->getAttribute('aria-label'))->not->toBeEmpty();

    return $document->saveHTML($sidebars->item(0));
}

test('navigation contains only real application destinations with route based active state', function (): void {
    $tenant = navigationTenant('navigation');
    $application = navigationApplication($tenant, 'Selected');
    $this->actingAs(navigationAdmin());
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    navigationRoute('filament.admin.resources.tenant-applications.edit', (string) $application->id);

    $navigation = ApplicationNavigation::forTenant($tenant);
    $items = collect($navigation['groups'])->flatMap(fn (array $group): array => $group['items'])->keyBy('label');

    expect($navigation['application']->is($application))->toBeTrue();
    expect($items->keys()->all())->toBe(['Overview', 'API keys', 'Users', 'Settings', 'Environment', 'Appearance', 'Authentication', 'Social connections']);
    expect($items['Overview'])->toBe([
        'label' => 'Overview',
        'icon' => 'heroicon-o-squares-2x2',
        'url' => TenantApplicationResource::getUrl('view', ['tenant' => $tenant, 'record' => $application]),
        'active' => false,
        'fragment' => null,
    ]);
    expect($items['API keys']['fragment'])->toBe('api-keys');
    expect($items['Environment']['url'])->toBe(TenantApplicationResource::getUrl('edit', ['tenant' => $tenant, 'record' => $application]));
    expect($items['Environment']['active'])->toBeTrue();
    expect($items['Authentication']['url'])->toBe(TenantApplicationResource::getUrl('authentication', ['tenant' => $tenant, 'record' => $application]));
    expect($items['Authentication']['fragment'])->toBeNull();
    expect($items['Social connections']['url'])->toBe(TenantApplicationResource::getUrl('social-connections', ['tenant' => $tenant, 'record' => $application]));
    expect($items['Social connections']['fragment'])->toBeNull();
    expect($items['Appearance']['url'])->toBe(TenantApplicationResource::getUrl('appearance', ['tenant' => $tenant, 'record' => $application]));
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

    navigationRoute('filament.admin.resources.tenant-applications.appearance', $routed);
    expect(ApplicationNavigation::currentApplication($tenant)->is($routed))->toBeTrue();
    expect(ApplicationNavigation::forTenant($tenant)['application']->is($routed))->toBeTrue();

    navigationRoute('filament.admin.resources.tenant-applications.social-connections', $routed);
    expect(ApplicationNavigation::currentApplication($tenant)->is($routed))->toBeTrue();
    expect(ApplicationNavigation::forTenant($tenant)['application']->is($routed))->toBeTrue();

    navigationRoute('filament.admin.resources.tenant-applications.authentication', $routed);
    expect(ApplicationNavigation::currentApplication($tenant)->is($routed))->toBeTrue();
    expect(ApplicationNavigation::forTenant($tenant)['application']->is($routed))->toBeTrue();

    navigationRoute('filament.admin.resources.tenant-applications.edit', (string) $foreign->id);
    expect(ApplicationNavigation::forTenant($tenant))->toBe(['application' => null, 'groups' => []]);
    expect(session('current_application_id'))->toBe($routed->id);

    navigationRoute('filament.admin.resources.tenant-applications.view', '999999999');
    expect(ApplicationNavigation::forTenant($tenant))->toBe(['application' => null, 'groups' => []]);

    navigationRoute('filament.admin.resources.users.view', $selected->id);
    expect(ApplicationNavigation::currentApplication($tenant)->is($routed))->toBeTrue();
    expect(ApplicationNavigation::forTenant($tenant))->toBe(['application' => null, 'groups' => []]);
    expect(ApplicationNavigation::forTenant(null))->toBe(['application' => null, 'groups' => []]);
});

test('topbar selection falls back within the current tenant without creating sidebar context', function (): void {
    $tenant = navigationTenant('navigation');
    $other = navigationTenant('other');
    $foreign = navigationApplication($other, 'Foreign');
    session(['current_application_id' => $foreign->id]);
    navigationRoute('filament.admin.resources.tenant-applications.index');

    expect(ApplicationNavigation::forTenant($tenant))->toBe(['application' => null, 'groups' => []]);

    $application = navigationApplication($tenant, 'Default');
    expect(ApplicationNavigation::currentApplication($tenant)->is($application))->toBeTrue();
    expect(session('current_application_id'))->toBe($application->id);
    expect(ApplicationNavigation::forTenant($tenant))->toBe(['application' => null, 'groups' => []]);
});

test('navigation applies view and edit permissions independently', function (bool $view, bool $edit, array $labels): void {
    $tenant = navigationTenant('navigation');
    $application = navigationApplication($tenant, 'Selected');
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    navigationRoute('filament.admin.resources.tenant-applications.edit', $application->id);
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
    'both' => [true, true, ['Application', 'Configuration']],
]);

test('application users navigation and page require both application view and user listing permissions', function (bool $viewApplication, bool $listUsers): void {
    $tenant = navigationTenant('navigation');
    $application = navigationApplication($tenant, 'Selected');
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    navigationRoute('filament.admin.resources.tenant-applications.users', $application->id);
    Gate::before(fn (User $user, string $ability, array $arguments): ?bool => match (true) {
        $ability === 'view' && ($arguments[0] ?? null) instanceof TenantApplication => $viewApplication,
        $ability === 'viewAny' && ($arguments[0] ?? null) === User::class => $listUsers,
        default => null,
    });

    $items = collect(ApplicationNavigation::forTenant($tenant)['groups'])->flatMap(fn (array $group): array => $group['items'])->keyBy('label');

    expect($items->has('Users'))->toBe($viewApplication && $listUsers);
    expect(ListApplicationUsers::canAccess(['record' => $application]))->toBe($viewApplication && $listUsers);
    if ($viewApplication && $listUsers) {
        expect($items['Users']['active'])->toBeTrue();
    }
})->with([
    'both' => [true, true],
    'application only' => [true, false],
    'users only' => [false, true],
    'neither' => [false, false],
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
        ->assertSee('id="environment-callbacks"', false);

    // Hosted login appearance, authentication, and social connections live on
    // their own pages and keep the application sidebar.
    $this->get(TenantApplicationResource::getUrl('appearance', ['tenant' => $tenant, 'record' => $second]))
        ->assertOk()
        ->assertSee('Second application')
        ->assertSee('id="edit-appearance-form"', false)
        ->assertSee('class="fi-app-nav"', false);

    $this->get(TenantApplicationResource::getUrl('authentication', ['tenant' => $tenant, 'record' => $second]))
        ->assertOk()
        ->assertSee('Second application')
        ->assertSee('id="edit-authentication-form"', false)
        ->assertSee('class="fi-app-nav"', false);

    $this->get(TenantApplicationResource::getUrl('social-connections', ['tenant' => $tenant, 'record' => $second]))
        ->assertOk()
        ->assertSee('Second application')
        ->assertSee('id="edit-social-connections-form"', false)
        ->assertSee('class="fi-app-nav"', false);
});

test('application record pages render the application navigation alongside the workspace navigation', function (string $page): void {
    $tenant = navigationTenant('navigation');
    $selected = navigationApplication($tenant, 'Previously selected');
    $routed = navigationApplication($tenant, 'Opened directly');
    $this->actingAs(navigationAdmin());
    $editUrl = TenantApplicationResource::getUrl('edit', ['tenant' => $tenant, 'record' => $routed]);
    $staleEditUrl = TenantApplicationResource::getUrl('edit', ['tenant' => $tenant, 'record' => $selected]);

    $response = $this->withSession(['current_application_id' => $selected->id])
        ->get(TenantApplicationResource::getUrl($page, ['tenant' => $tenant, 'record' => $routed]))
        ->assertOk()
        ->assertSee('View all applications')
        ->assertSee('Organization settings')
        ->assertSessionHas('current_application_id', $routed->id);

    $sidebar = navigationSidebar($response);
    expect($sidebar)
        ->toContain('Opened directly', 'Overview', 'API keys', 'Users', 'Settings', 'Environment', 'Appearance', 'Authentication', 'Social connections')
        ->toContain('href="'.$editUrl.'#environment-callbacks"')
        ->not->toContain('href="'.$staleEditUrl.'#environment-callbacks"')
        ->not->toContain('fi-dd-', 'Search settings', 'type="search"');

    // The application section reuses Filament's sidebar components, so it is
    // rendered as native groups and items rather than custom markup.
    $document = new DOMDocument;
    $document->loadHTML($sidebar, LIBXML_NOERROR | LIBXML_NOWARNING);
    $appNav = (new DOMXPath($document))->query('//div[@class="fi-app-nav"]');
    expect($appNav)->toHaveCount(1);
    expect((new DOMXPath($document))->query('//div[@class="fi-app-nav"]//li[contains(@class, "fi-sidebar-item")]'))->toHaveCount(8);

    $appNavHtml = $document->saveHTML($appNav->item(0));
    expect($appNavHtml)
        ->toContain('data-group-label="Application"', 'data-group-label="Directory"', 'data-group-label="Configuration"')
        ->toContain('fi-sidebar-group-items', 'fi-sidebar-item-btn', 'fi-sidebar-item-label')
        ->toContain('Opened directly');

    // Active state for fragment targets is resolved from the URL hash in the browser.
    $xpath = new DOMXPath($document);
    $routeActive = $page === 'edit' ? 'true' : 'false';

    $fragmentLink = $xpath->query('//div[@class="fi-app-nav"]//a[contains(@href, "#environment-callbacks")]');
    expect($fragmentLink)->toHaveCount(1);
    expect($fragmentLink->item(0)->parentNode->getAttribute('x-bind:class'))
        ->toBe("{ 'fi-active': isActive({$routeActive}, 'environment-callbacks') }");

    $settingsLink = $xpath->query('//div[@class="fi-app-nav"]//a[normalize-space(.)="Settings"]');
    expect($settingsLink)->toHaveCount(1);
    expect($settingsLink->item(0)->parentNode->getAttribute('x-bind:class'))
        ->toBe("{ 'fi-active': isActive({$routeActive}, null) }");

    // The workspace navigation is retained instead of being replaced.
    expect($sidebar)
        ->toContain('data-group-label="Applications"', 'data-group-label="Directory &amp; Access"')
        ->toContain('href="'.route('filament.admin.resources.tenant-applications.index', ['tenant' => $tenant]).'"')
        ->toContain('href="'.OAuthClientResource::getUrl('index', ['tenant' => $tenant]).'"')
        ->toContain('href="'.route('filament.admin.resources.users.index', ['tenant' => $tenant]).'"');
})->with(['view', 'edit', 'appearance', 'social-connections', 'authentication', 'users']);

test('workspace pages render both collapsible groups with tenant links and the active group', function (string $route, string $activeGroup): void {
    $tenant = navigationTenant('navigation');
    $application = navigationApplication($tenant, 'Selected application');
    $this->actingAs(navigationAdmin());

    $response = $this->withSession(['current_application_id' => $application->id])
        ->get(route($route, ['tenant' => $tenant]))
        ->assertOk()
        ->assertSee('Selected application')
        ->assertSee('Organization settings');

    $sidebar = navigationSidebar($response);
    $document = new DOMDocument;
    $document->loadHTML($sidebar, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    foreach (['Applications', 'Directory & Access'] as $group) {
        $groups = $xpath->query('//li[@data-group-label="'.$group.'"]');
        expect($groups)->toHaveCount(1);
        $groupElement = $groups->item(0);
        expect($groupElement->getAttribute('class'))->toContain('fi-collapsible');
        expect(str_contains($groupElement->getAttribute('class'), 'fi-active'))->toBe($group === $activeGroup);
        $buttons = $xpath->query('.//button[@aria-controls]', $groupElement);
        expect($buttons)->toHaveCount(1);
        expect($buttons->item(0)->getAttribute('x-bind:aria-expanded'))->not->toBeEmpty();
    }

    expect($sidebar)
        ->toContain(
            'href="'.route('filament.admin.resources.tenant-applications.index', ['tenant' => $tenant]).'"',
            'href="'.OAuthClientResource::getUrl('index', ['tenant' => $tenant]).'"',
            'href="'.WebhookEndpointResource::getUrl('index', ['tenant' => $tenant]).'"',
            'href="'.route('filament.admin.resources.users.index', ['tenant' => $tenant]).'"',
            'filament:sidebar:collapsed_groups',
        )
        ->not->toContain('fi-dd-', 'goBack()', 'class="fi-app-nav"', 'Search settings', 'Select an application to see its settings.');
})->with([
    'application list' => ['filament.admin.resources.tenant-applications.index', 'Applications'],
    'application creation' => ['filament.admin.resources.tenant-applications.create', 'Applications'],
    'directory' => ['filament.admin.resources.users.index', 'Directory & Access'],
]);

test('desktop collapsed navigation retains group icon dropdowns and their links', function (): void {
    $tenant = navigationTenant('navigation');
    $this->actingAs(navigationAdmin());
    $panel = Filament::getPanel('admin');
    $panel->sidebarCollapsibleOnDesktop();
    $panel->navigationGroups([
        NavigationGroup::make('Applications')->icon('heroicon-o-cube'),
        NavigationGroup::make('Directory & Access')->icon('heroicon-o-users'),
    ]);

    $response = $this->get(route('filament.admin.resources.users.index', ['tenant' => $tenant]))->assertOk();

    $sidebar = navigationSidebar($response);
    expect($sidebar)
        ->toContain('fi-sidebar-group-dropdown-trigger-btn', 'aria-label="Applications"', 'aria-label="Directory &amp; Access"')
        ->toContain('href="'.TenantApplicationResource::getUrl('index', ['tenant' => $tenant]).'"')
        ->not->toContain('fi-dd-');
});

test('workspace navigation excludes resources the user cannot view', function (): void {
    $tenant = navigationTenant('navigation');
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $ability === 'viewAny'
        ? ($arguments[0] ?? null) === TenantApplication::class
        : null);

    $sidebar = Livewire::test(Sidebar::class)->html();

    expect($sidebar)
        ->toContain(route('filament.admin.resources.tenant-applications.index', ['tenant' => $tenant]))
        ->not->toContain(
            route('filament.admin.resources.users.index', ['tenant' => $tenant]),
        );
});

test('an application record from another tenant returns not found without replacing the selection', function (string $page): void {
    $tenant = navigationTenant('navigation');
    $other = navigationTenant('other');
    $selected = navigationApplication($tenant, 'Selected application');
    $foreign = navigationApplication($other, 'Foreign application');
    $this->actingAs(navigationAdmin());

    $this->withSession(['current_application_id' => $selected->id])
        ->get(TenantApplicationResource::getUrl($page, ['tenant' => $tenant, 'record' => $foreign]))
        ->assertNotFound()
        ->assertSessionHas('current_application_id', $selected->id);
})->with(['view', 'edit', 'users']);

test('application pages remain forbidden to application users', function (string $page): void {
    $tenant = navigationTenant('navigation');
    $application = navigationApplication($tenant, 'Protected application');
    $this->actingAs(User::factory()->create());

    $this->get(TenantApplicationResource::getUrl($page, ['tenant' => $tenant, 'record' => $application]))
        ->assertForbidden()
        ->assertDontSee('class="fi-app-nav"', false);
})->with(['view', 'edit', 'users']);
