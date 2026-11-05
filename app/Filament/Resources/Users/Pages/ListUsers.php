<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
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
            Action::make('quickAdd')
                ->label('Quick Add on Dashboard')
                ->url(fn (): string => Dashboard::getUrl()),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Application Users')
                ->badge(fn (): int => static::getResource()::getEloquentQuery()->count()),
            'active' => Tab::make('Active')
                ->badge(fn (): int => static::getResource()::getEloquentQuery()->whereNull('banned_at')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('banned_at')),
            'suspended' => Tab::make('Suspended')
                ->badge(fn (): int => static::getResource()::getEloquentQuery()->whereNotNull('banned_at')->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('banned_at')),
            'unverified' => Tab::make('Unverified')
                ->badge(fn (): int => static::getResource()::getEloquentQuery()->whereNull('email_verified_at')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('email_verified_at')),
        ];
    }
}
