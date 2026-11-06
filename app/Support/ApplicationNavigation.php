<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Tenant;
use App\Models\TenantApplication;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

class ApplicationNavigation
{
    /**
     * @return array{application: ?TenantApplication, groups: list<array{label: string, items: list<array{label: string, icon: string, url: string, active: bool, fragment: ?string}>}>}
     */
    public static function forTenant(?Tenant $tenant): array
    {
        if (! self::isApplicationRecordRoute()) {
            return ['application' => null, 'groups' => []];
        }

        $application = self::currentApplication($tenant);
        $groups = [];

        if (! $tenant instanceof Tenant || ! $application instanceof TenantApplication) {
            return ['application' => null, 'groups' => []];
        }

        if (TenantApplicationResource::canView($application)) {
            $url = TenantApplicationResource::getUrl('view', ['tenant' => $tenant, 'record' => $application]);
            $active = request()->routeIs('filament.admin.resources.tenant-applications.view');
            $groups[] = [
                'label' => 'Application',
                'items' => [
                    ['label' => 'Overview', 'icon' => 'heroicon-o-squares-2x2', 'url' => $url, 'active' => $active, 'fragment' => null],
                    ['label' => 'API keys', 'icon' => 'heroicon-o-key', 'url' => $url, 'active' => $active, 'fragment' => 'api-keys'],
                ],
            ];
        }

        if (TenantApplicationResource::canView($application) && UserResource::canViewAny()) {
            $groups[] = [
                'label' => 'Directory',
                'items' => [
                    [
                        'label' => 'Users',
                        'icon' => 'heroicon-o-users',
                        'url' => TenantApplicationResource::getUrl('users', ['tenant' => $tenant, 'record' => $application]),
                        'active' => request()->routeIs('filament.admin.resources.tenant-applications.users'),
                        'fragment' => null,
                    ],
                ],
            ];
        }

        if (TenantApplicationResource::canEdit($application)) {
            $url = TenantApplicationResource::getUrl('edit', ['tenant' => $tenant, 'record' => $application]);
            $active = request()->routeIs('filament.admin.resources.tenant-applications.edit');
            $groups[] = [
                'label' => 'Configuration',
                'items' => [
                    ['label' => 'Settings', 'icon' => 'heroicon-o-cog-6-tooth', 'url' => $url, 'active' => $active, 'fragment' => null],
                    ['label' => 'Environment', 'icon' => 'heroicon-o-globe-alt', 'url' => $url, 'active' => $active, 'fragment' => 'environment-callbacks'],
                    [
                        'label' => 'Appearance',
                        'icon' => 'heroicon-o-paint-brush',
                        'url' => TenantApplicationResource::getUrl('appearance', ['tenant' => $tenant, 'record' => $application]),
                        'active' => request()->routeIs('filament.admin.resources.tenant-applications.appearance'),
                        'fragment' => null,
                    ],
                    ['label' => 'Authentication features', 'icon' => 'heroicon-o-shield-check', 'url' => $url, 'active' => $active, 'fragment' => 'application-authentication-features'],
                    [
                        'label' => 'Social connections',
                        'icon' => 'heroicon-o-share',
                        'url' => TenantApplicationResource::getUrl('social-connections', ['tenant' => $tenant, 'record' => $application]),
                        'active' => request()->routeIs('filament.admin.resources.tenant-applications.social-connections'),
                        'fragment' => null,
                    ],
                ],
            ];
        }

        return ['application' => $application, 'groups' => $groups];
    }

    /**
     * Build render-ready Filament navigation for the selected application so its
     * section reuses the same sidebar components as the workspace navigation.
     *
     * @param  list<array{label: string, items: list<array{label: string, icon: string, url: string, active: bool, fragment: ?string}>}>  $groups
     * @return list<NavigationGroup>
     */
    public static function navigationGroups(array $groups): array
    {
        return array_map(
            fn (array $group): NavigationGroup => NavigationGroup::make($group['label'])
                ->items(array_map(self::navigationItem(...), $group['items'])),
            $groups,
        );
    }

    /**
     * @param  array{label: string, icon: string, url: string, active: bool, fragment: ?string}  $item
     */
    private static function navigationItem(array $item): NavigationItem
    {
        $navigationItem = NavigationItem::make($item['label'])
            ->icon($item['icon'])
            ->url($item['url'].(filled($item['fragment']) ? '#'.$item['fragment'] : ''))
            ->extraAttributes([
                'x-bind:class' => "{ 'fi-active': ".self::activeExpression($item).' }',
                'x-bind:aria-current' => self::activeExpression($item)." ? 'page' : null",
            ]);

        // Fragment destinations share one route, so which of them is active is
        // resolved from the URL hash in the browser instead of on the server.
        if (blank($item['fragment'])) {
            $navigationItem->isActiveWhen(fn (): bool => $item['active']);
        }

        return $navigationItem;
    }

    /**
     * @param  array{active: bool, fragment: ?string}  $item
     */
    private static function activeExpression(array $item): string
    {
        $fragment = $item['fragment'] === null
            ? 'null'
            : "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $item['fragment'])."'";

        return sprintf('isActive(%s, %s)', $item['active'] ? 'true' : 'false', $fragment);
    }

    public static function currentApplication(?Tenant $tenant): ?TenantApplication
    {
        if (! $tenant instanceof Tenant) {
            return null;
        }

        if (! self::isApplicationRecordRoute()) {
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

    private static function isApplicationRecordRoute(): bool
    {
        return request()->routeIs(
            'filament.admin.resources.tenant-applications.view',
            'filament.admin.resources.tenant-applications.edit',
            'filament.admin.resources.tenant-applications.appearance',
            'filament.admin.resources.tenant-applications.social-connections',
            'filament.admin.resources.tenant-applications.users',
        );
    }
}
