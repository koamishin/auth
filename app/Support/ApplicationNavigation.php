<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\Tenant;
use App\Models\TenantApplication;

class ApplicationNavigation
{
    /**
     * @return array{application: ?TenantApplication, groups: list<array{label: string, items: list<array{label: string, url: string, active: bool, fragment: ?string}>}>}
     */
    public static function forTenant(?Tenant $tenant): array
    {
        $application = self::currentApplication($tenant);
        $groups = [];

        if (!$tenant instanceof \App\Models\Tenant || !$application instanceof \App\Models\TenantApplication) {
            return ['application' => null, 'groups' => []];
        }

        if (TenantApplicationResource::canView($application)) {
            $url = TenantApplicationResource::getUrl('view', ['tenant' => $tenant, 'record' => $application]);
            $active = request()->routeIs('filament.admin.resources.tenant-applications.view');
            $groups[] = [
                'label' => 'Application',
                'items' => [
                    ['label' => 'Overview', 'url' => $url, 'active' => $active, 'fragment' => null],
                    ['label' => 'API keys', 'url' => $url, 'active' => $active, 'fragment' => 'api-keys'],
                ],
            ];
        }

        if (TenantApplicationResource::canEdit($application)) {
            $url = TenantApplicationResource::getUrl('edit', ['tenant' => $tenant, 'record' => $application]);
            $active = request()->routeIs('filament.admin.resources.tenant-applications.edit');
            $groups[] = [
                'label' => 'Configuration',
                'items' => [
                    ['label' => 'Settings', 'url' => $url, 'active' => $active, 'fragment' => null],
                    ['label' => 'Environment', 'url' => $url, 'active' => $active, 'fragment' => 'environment-callbacks'],
                    ['label' => 'Appearance', 'url' => $url, 'active' => $active, 'fragment' => 'hosted-login-appearance'],
                    ['label' => 'Authentication features', 'url' => $url, 'active' => $active, 'fragment' => 'application-authentication-features'],
                    ['label' => 'Social connections', 'url' => $url, 'active' => $active, 'fragment' => 'social-connections'],
                ],
            ];
        }

        return ['application' => $application, 'groups' => $groups];
    }

    public static function currentApplication(?Tenant $tenant): ?TenantApplication
    {
        if (!$tenant instanceof \App\Models\Tenant) {
            return null;
        }

        if (! request()->routeIs('filament.admin.resources.tenant-applications.view', 'filament.admin.resources.tenant-applications.edit')) {
            return TenantApplication::current($tenant);
        }

        $record = request()->route('record');
        $key = $record instanceof TenantApplication ? $record->getKey() : $record;

        if (! is_string($key) && ! is_int($key)) {
            return null;
        }

        $application = $tenant->applications()->find($key);

        if ($application !== null) {
            session(['current_application_id' => $application->getKey()]);
        }

        return $application;
    }
}
