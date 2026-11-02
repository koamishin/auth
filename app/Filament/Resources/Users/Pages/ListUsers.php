<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'application_users' => Tab::make('Application Users')
                ->badge(fn (): int => User::query()->whereDoesntHave('roles', fn (Builder $q) => $q->where('name', 'super_admin'))->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDoesntHave('roles', fn (Builder $q) => $q->where('name', 'super_admin'))),
            'system_admins' => Tab::make('System Administrators')
                ->badge(fn (): int => User::query()->whereHas('roles', fn (Builder $q) => $q->where('name', 'super_admin'))->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('roles', fn (Builder $q) => $q->where('name', 'super_admin'))),
            'all' => Tab::make('All Accounts')
                ->badge(fn (): int => User::query()->count()),
        ];
    }
}
