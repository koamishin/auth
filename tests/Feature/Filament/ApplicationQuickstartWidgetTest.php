<?php

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
        ->assertSee('Quickstart Integration Guide')
        ->assertSee('Next.js')
        ->assertSee('Install JavaScript SDK')
        ->assertSee('npm install @koamishin/auth-js')
        ->call('setStep', 2)
        ->assertSee('NEXT_PUBLIC_KOAMISHIN_CLIENT_ID='.$app->oauth_client_id)
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
        ->call('setStep', 2)
        ->assertSee('VITE_KOAMISHIN_CLIENT_ID='.$app->oauth_client_id)
        ->call('setFramework', 'nodejs')
        ->call('setStep', 1)
        ->assertSee('npm install express axios dotenv');
});
