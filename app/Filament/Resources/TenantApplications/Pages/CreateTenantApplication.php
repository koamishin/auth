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

    public function getProjectType(): string
    {
        return $this->data['project_type'] ?? 'consumer';
    }

    public function getAppName(): string
    {
        return filled($this->data['name'] ?? null) ? (string) $this->data['name'] : 'My Application';
    }

    /**
     * @return array{email: bool, phone: bool, username: bool, google: bool, github: bool, passkeys: bool}
     */
    public function getAuthOptions(): array
    {
        return [
            'email' => (bool) ($this->data['auth_email'] ?? true),
            'phone' => (bool) ($this->data['auth_phone'] ?? false),
            'username' => (bool) ($this->data['auth_username'] ?? false),
            'google' => (bool) ($this->data['auth_google'] ?? true),
            'github' => (bool) ($this->data['auth_github'] ?? false),
            'passkeys' => (bool) ($this->data['auth_passkeys'] ?? true),
        ];
    }

    public function setProjectType(string $type): void
    {
        if (in_array($type, ['consumer', 'b2b'], true)) {
            $this->data['project_type'] = $type;
        }
    }

    public function toggleAuthOption(string $option): void
    {
        $key = 'auth_'.$option;
        if (array_key_exists($key, $this->data)) {
            $this->data[$key] = ! $this->data[$key];
        } else {
            $this->data[$key] = true;
        }
    }

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
