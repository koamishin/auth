<?php

namespace App\Providers\Filament;

use AlizHarb\ActivityLog\ActivityLogPlugin;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\EditTenantProfile;
use App\Filament\Pages\Tenancy\RegisterTenant;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\FeatureFlag\FeatureFlagResource;
use App\Filament\Resources\SystemUsers\SystemUserResource;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use OsamaAtef\DrilldownSidebar\DrilldownSidebarPlugin;
use RobertBoes\FilamentPasskeys\FilamentPasskeysPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => Blade::render('@include("impersonate-banner")'),
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_LOGO_AFTER,
                fn (): string => Blade::render('@include("filament.components.topbar-context-selector")'),
            )
            ->tenantMenu(false)
            ->databaseNotifications()
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->profile(EditProfile::class, isSimple: false)
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action
                    ->label('My profile')
                    ->icon('heroicon-m-user-circle')
                    ->url(fn (): string => EditProfile::getUrl()),
                'settings' => MenuItem::make()
                    ->label('System Settings')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->sort(10)
                    ->url(function (): string {
                        $tenant = Filament::getTenant() ?? Auth::user()?->tenants()->first() ?? Tenant::first();

                        return $tenant ? SettingsCluster::getUrl(['tenant' => $tenant]) : '#';
                    }),
                'system_users' => MenuItem::make()
                    ->label('System Users')
                    ->icon('heroicon-o-shield-check')
                    ->sort(15)
                    ->url(function (): string {
                        $tenant = Filament::getTenant() ?? Auth::user()?->tenants()->first() ?? Tenant::first();

                        return $tenant ? SystemUserResource::getUrl('index', ['tenant' => $tenant]) : '#';
                    })
                    ->visible(fn (): bool => Auth::user()?->hasRole('super_admin') ?? false),
                'roles' => MenuItem::make()
                    ->label('Roles & Permissions')
                    ->icon('heroicon-o-shield-check')
                    ->sort(20)
                    ->url(function (): string {
                        $tenant = Filament::getTenant() ?? Auth::user()?->tenants()->first() ?? Tenant::first();

                        return $tenant ? RoleResource::getUrl('index', ['tenant' => $tenant]) : '#';
                    })
                    ->visible(fn (): bool => Auth::user()?->can('ViewAny:Role') ?? false),
                'activity_logs' => MenuItem::make()
                    ->label('Activity Logs')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->sort(30)
                    ->url(function (): string {
                        $tenant = Filament::getTenant() ?? Auth::user()?->tenants()->first() ?? Tenant::first();

                        return $tenant ? ActivityLogResource::getUrl('index', ['tenant' => $tenant]) : '#';
                    })
                    ->visible(fn (): bool => Auth::user()?->can('ViewAny:Activity') ?? false),
                'feature_flags' => MenuItem::make()
                    ->label('Feature Flags')
                    ->icon('heroicon-o-flag')
                    ->sort(40)
                    ->url(function (): string {
                        $tenant = Filament::getTenant() ?? Auth::user()?->tenants()->first() ?? Tenant::first();

                        return $tenant ? FeatureFlagResource::getUrl('index', ['tenant' => $tenant]) : '#';
                    })
                    ->visible(fn (): bool => Auth::user()?->hasRole('super_admin') ?? false),
                'tenants' => MenuItem::make()
                    ->label('Organizations')
                    ->icon('heroicon-o-building-office-2')
                    ->sort(50)
                    ->url(function (): string {
                        $tenant = Filament::getTenant() ?? Auth::user()?->tenants()->first() ?? Tenant::first();

                        return $tenant ? TenantResource::getUrl('index', ['tenant' => $tenant]) : '#';
                    })
                    ->visible(fn (): bool => Auth::user()?->hasRole('super_admin') ?? false),
            ])
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
                EmailAuthentication::make(),
            ])
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterTenant::class)
            ->tenantProfile(EditTenantProfile::class)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->plugins([
                DrilldownSidebarPlugin::make()
                    ->drilledGroups([
                        'Applications',
                        'Directory & Access',
                    ])
                    ->withSearch(),
                FilamentShieldPlugin::make()
                    ->registerNavigation(false)
                    ->gridColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 3,
                    ])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 4,
                    ])
                    ->resourceCheckboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                    ]),
                ActivityLogPlugin::make()
                    ->label('Log')
                    ->pluralLabel('Logs')
                    ->navigationGroup('System'),
                FilamentPasskeysPlugin::make(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
