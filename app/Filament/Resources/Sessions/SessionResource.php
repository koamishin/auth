<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sessions;

use App\Filament\Resources\Sessions\Pages\ListSessions;
use App\Filament\Resources\Users\UserResource;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\TenantApplicationEnvironment;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SessionResource extends Resource
{
    protected static ?string $model = Session::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-computer-desktop';

    protected static \UnitEnum|string|null $navigationGroup = 'Directory & Access';

    protected static ?string $navigationLabel = 'Active Sessions';

    protected static ?string $modelLabel = 'Session';

    protected static ?string $pluralModelLabel = 'Active Sessions';

    protected static ?int $navigationSort = 2;

    protected static bool $isScopedToTenant = false;

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenant = Filament::getTenant();

        if ($tenant instanceof Tenant) {
            $envIds = TenantApplicationEnvironment::query()
                ->whereHas('application', fn ($q) => $q->where('tenant_id', $tenant->getKey()))
                ->pluck('id')
                ->all();
            $namespaces = array_map(fn ($id): string => 'environment:'.$id, $envIds);

            $query->whereHas('user', function ($userQuery) use ($tenant, $namespaces): void {
                $userQuery->whereHas('tenants', fn ($t) => $t->whereKey($tenant->getKey()));
                if ($namespaces !== []) {
                    $userQuery->orWhereIn('identity_namespace', $namespaces);
                }
            });
        }

        return $query;
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->whereNotNull('user_id')->latest('last_activity'))
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->url(fn (Session $record): ?string => $record->user_id ? UserResource::getUrl('view', ['record' => $record->user_id]) : null),

                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('device')
                    ->label('Client Device')
                    ->state(fn (Session $record): string => $record->getDeviceName())
                    ->icon('heroicon-o-device-phone-mobile'),

                TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('last_activity')
                    ->label('Last Active')
                    ->state(fn (Session $record): string => $record->last_active->diffForHumans())
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Terminate')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->successNotification(
                        Notification::make()
                            ->title('Session Terminated')
                            ->body('The user will be logged out on their next request.')
                            ->warning()
                    ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Terminate Selected'),
                ]),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSessions::route('/'),
        ];
    }
}
