<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\TenantApplication;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ListApplicationUsers extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = TenantApplicationResource::class;

    protected static ?string $title = 'Users';

    protected string $view = 'filament.resources.tenant-applications.pages.list-application-users';

    #[Unexposed]
    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public static function canAccess(array $parameters = []): bool
    {
        $application = $parameters['record'] ?? null;

        return $application instanceof TenantApplication
            && TenantApplicationResource::canView($application)
            && UserResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table
            ->relationship(function (): BelongsToMany {
                /** @var TenantApplication $application */
                $application = $this->getRecord();

                return $application->users($application->environment);
            })
            ->inverseRelationship('applications')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('pivot.role')->label('Application role')->badge(),
                TextColumn::make('pivot.created_at')->label('Enrolled')->dateTime('M j, Y H:i'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No users enrolled')
            ->emptyStateDescription('Users enrolled in this application and environment will appear here.');
    }
}
