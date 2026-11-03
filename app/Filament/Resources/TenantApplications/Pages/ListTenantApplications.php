<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\Tenant;
use App\Models\TenantApplication;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ListTenantApplications extends ListRecords
{
    protected static string $resource = TenantApplicationResource::class;

    protected string $view = 'filament.resources.tenant-applications.pages.list-applications';

    public string $search = '';

    public string $environmentFilter = 'all';

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Application'),
        ];
    }

    public function getActiveTenant(): ?Tenant
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * @return array{total: int, production: int, development: int, users_count: int}
     */
    public function getMetrics(): array
    {
        $tenant = $this->getActiveTenant();
        if (! $tenant instanceof Tenant) {
            return ['total' => 0, 'production' => 0, 'development' => 0, 'users_count' => 0];
        }

        $apps = $tenant->applications()->get();
        $total = $apps->count();
        $production = $apps->where('environment', 'production')->count();
        $development = $apps->where('environment', 'development')->count();

        $usersCount = DB::table('tenant_application_user')
            ->whereIn('tenant_application_id', $apps->pluck('id'))
            ->distinct('user_id')
            ->count('user_id');

        return [
            'total' => $total,
            'production' => $production,
            'development' => $development,
            'users_count' => $usersCount,
        ];
    }

    /**
     * @return LengthAwarePaginator<TenantApplication>
     */
    public function getApplications(): LengthAwarePaginator
    {
        $tenant = $this->getActiveTenant();
        if (! $tenant instanceof Tenant) {
            return new LengthAwarePaginator([], 0, 9);
        }

        return $tenant->applications()
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $sub): void {
                    $sub->where('name', 'like', "%{$this->search}%")
                        ->orWhere('oauth_client_id', 'like', "%{$this->search}%")
                        ->orWhere('description', 'like', "%{$this->search}%")
                        ->orWhereHas('environments', fn (Builder $env) => $env->where('publishable_key', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->environmentFilter !== 'all', fn (Builder $q) => $q->where('environment', $this->environmentFilter))
            ->with(['environments', 'oauthClient'])
            ->withCount('users')
            ->latest('updated_at')
            ->paginate(9);
    }

    public function deleteApplication(int $appId): void
    {
        $tenant = $this->getActiveTenant();
        if (! $tenant instanceof Tenant) {
            return;
        }

        $app = $tenant->applications()->find($appId);
        if (! $app) {
            return;
        }

        $name = $app->name;
        $app->delete();

        if (session('current_application_id') === $appId) {
            session()->forget('current_application_id');
        }

        Notification::make()
            ->title('Application Deleted')
            ->body("{$name} has been removed.")
            ->danger()
            ->send();
    }

    public function switchApp(int $appId): void
    {
        session(['current_application_id' => $appId]);

        Notification::make()
            ->title('Active Context Switched')
            ->success()
            ->send();
    }
}
