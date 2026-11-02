<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\FeatureFlag\FeatureFlagResource;
use App\Filament\Resources\SystemUsers\SystemUserResource;
use App\Filament\Resources\Tenants\TenantResource;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class SystemAdministrationPage extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected string $view = 'filament.clusters.settings.pages.system-administration-page';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'System Administration';

    protected static ?string $navigationLabel = 'System Administration';

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * @return array<array{title: string, description: string, icon: string, url: string}>
     */
    public function getSystemNavigations(): array
    {
        $tenant = Filament::getTenant();
        $params = $tenant ? ['tenant' => $tenant] : [];

        return [
            [
                'title' => 'System Users & Staff',
                'description' => 'Manage system administrators, staff panel accounts, and administrative credentials.',
                'icon' => 'heroicon-o-users',
                'url' => SystemUserResource::getUrl('index', $params),
            ],
            [
                'title' => 'Roles & Permissions',
                'description' => 'Manage user roles, granular resource policies, and permission matrix.',
                'icon' => 'heroicon-o-shield-check',
                'url' => RoleResource::getUrl('index', $params),
            ],
            [
                'title' => 'Audit & Activity Logs',
                'description' => 'Review comprehensive audit trails, model change history, and user activity events.',
                'icon' => 'heroicon-o-clipboard-document-list',
                'url' => ActivityLogResource::getUrl('index', $params),
            ],
            [
                'title' => 'Feature Flags',
                'description' => 'Toggle rollout states, manage role-based overrides, and control platform features.',
                'icon' => 'heroicon-o-flag',
                'url' => FeatureFlagResource::getUrl('index', $params),
            ],
            [
                'title' => 'Organizations (Tenants)',
                'description' => 'Manage multi-tenant organizations, domains, and global tenant memberships.',
                'icon' => 'heroicon-o-building-office-2',
                'url' => TenantResource::getUrl('index', $params),
            ],
        ];
    }
}
