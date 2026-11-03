<?php

use App\Filament\Resources\TenantApplications\Pages\ViewTenantApplication;
use App\Filament\Widgets\ApplicationQuickstartWidget;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->tenant = Tenant::firstOrCreate(['slug' => 'default'], ['name' => 'Default Organization', 'is_active' => true]);
});

test('quickstart widget initializes with application framework and renders step by step instructions', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $app = app(ApplicationProvisioner::class)->create($this->tenant, [
        'name' => 'Next App',
        'framework' => 'nextjs',
        'environment' => 'development',
        'redirect_uris' => ['http://localhost:3000/api/auth/callback'],
    ]);

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)
        ->test(ApplicationQuickstartWidget::class, ['record' => $app])
        ->assertSee('Add authentication to your app')
        ->assertSee('Next.js')
        ->assertSee('Install JavaScript SDK')
        ->assertSee('npm install @koamishin/auth-js')
        ->call('setStep', 2)
        ->assertSee('NEXT_PUBLIC_KOAMISHIN_PUBLISHABLE_KEY='.$app->getPublishableKey())
        ->call('setStep', 3)
        ->assertSee('lib/auth.ts')
        ->call('setStep', 4)
        ->assertSee('app/auth/callback/page.tsx');
});

test('quickstart widget allows switching framework tabs dynamically', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $app = app(ApplicationProvisioner::class)->create($this->tenant, [
        'name' => 'Polyglot App',
        'framework' => 'laravel',
        'environment' => 'production',
        'redirect_uris' => ['https://polyglot.test/callback'],
    ]);

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)
        ->test(ApplicationQuickstartWidget::class, ['record' => $app])
        ->assertSee('composer require koamishin/auth-php')
        ->call('setFramework', 'vue')
        ->assertSee('npm install @koamishin/auth-js')
        ->assertSee('This guide uses a public client')
        ->call('setStep', 2)
        ->assertSee('VITE_KOAMISHIN_PUBLISHABLE_KEY='.$app->getPublishableKey())
        ->call('setFramework', 'nodejs')
        ->call('setStep', 1)
        ->assertSee('npm install express express-session axios dotenv');
});

test('new applications show the guide and preserve one-time credentials while opening the selected connection form', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $app = app(ApplicationProvisioner::class)->create($this->tenant, ['name' => 'New App']);
    $secret = session('new_app_credentials.development.secret_key');

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)
        ->test(ViewTenantApplication::class, ['record' => $app->getKey()])
        ->assertSee('Add authentication to your app')
        ->assertSee($secret)
        ->call('openQuickstartConnection', 'react')
        ->assertActionDataSet(['framework' => 'react', 'client_type' => 'public', 'redirect_uris' => []])
        ->assertSee($secret)
        ->unmountAction()
        ->call('dismissNewCredentials')
        ->assertDontSee($secret);

    expect($app->fresh()->oauth_client_id)->toBeNull();
});

test('agent and mobile guides use the selected environment without exposing secret keys', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $app = app(ApplicationProvisioner::class)->create($this->tenant, ['name' => 'Agent App']);
    $secret = session('new_app_credentials.development.secret_key');

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)
        ->test(ApplicationQuickstartWidget::class, ['record' => $app])
        ->assertSee('Watching for users')
        ->assertSee('Register callback')
        ->assertSee('YOUR_REGISTERED_CALLBACK_URL')
        ->call('setFramework', 'vue')
        ->call('openConnectionSetup')
        ->assertDispatched('quickstart-connect', framework: 'vue')
        ->call('setFramework', 'mobile')
        ->assertSee('Custom-scheme callbacks are not supported yet')
        ->call('setSetupMode', 'agent')
        ->assertSee('Integrate Koamishin authentication into this Mobile application.')
        ->assertSee($app->getPublishableKey())
        ->assertDontSee($secret)
        ->call('setSetupMode', 'manual')
        ->assertSee('Choose an OAuth library')
        ->call('setFramework', 'nextjs')
        ->call('setPackageManager', 'pnpm')
        ->assertSee('pnpm add @koamishin/auth-js');

    expect($app->fresh()->framework)->toBeNull()
        ->and($app->oauth_client_id)->toBeNull();
});
