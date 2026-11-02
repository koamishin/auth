<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\Tenant;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenantApplication extends CreateRecord
{
    protected static string $resource = TenantApplicationResource::class;

    protected string $view = 'filament.resources.tenant-applications.pages.create-application';

    protected static ?string $title = 'Create Application';

    /**
     * @return array{name: string, badge: string, color: string, client_type: string, package: string}
     */
    public function getFrameworkInfo(): array
    {
        $framework = $this->data['framework'] ?? 'laravel';

        return match ($framework) {
            'nextjs' => [
                'name' => 'Next.js',
                'badge' => '▲ Next.js',
                'color' => 'gray',
                'client_type' => 'Public Client (PKCE)',
                'package' => 'npm install @koamishin/auth-js',
            ],
            'vue' => [
                'name' => 'Vue 3 / Nuxt',
                'badge' => 'V Vue',
                'color' => 'emerald',
                'client_type' => 'Public Client (PKCE)',
                'package' => 'npm install @koamishin/auth-js',
            ],
            'react' => [
                'name' => 'React (Vite / SPA)',
                'badge' => '⚛ React',
                'color' => 'cyan',
                'client_type' => 'Public Client (PKCE)',
                'package' => 'npm install @koamishin/auth-js',
            ],
            'nodejs' => [
                'name' => 'Node.js',
                'badge' => 'Node',
                'color' => 'amber',
                'client_type' => 'Confidential Client (Secret)',
                'package' => 'npm install @koamishin/auth-node',
            ],
            'vanilla' => [
                'name' => 'Vanilla JavaScript',
                'badge' => 'JS',
                'color' => 'amber',
                'client_type' => 'Public Client (PKCE)',
                'package' => 'npm install @koamishin/auth-js',
            ],
            default => [
                'name' => 'Laravel',
                'badge' => 'Lv Laravel',
                'color' => 'red',
                'client_type' => 'Confidential Client (Secret)',
                'package' => 'composer require koamishin/laravel-sdk',
            ],
        };
    }

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant() ?? Tenant::query()->findOrFail($data['tenant_id'] ?? null);
        if (! $tenant instanceof Tenant) {
            abort(403);
        }

        return app(ApplicationProvisioner::class)->create($tenant, $data);
    }

    #[\Override]
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
