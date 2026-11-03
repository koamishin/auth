<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\Tenant;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

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
            'google' => (bool) ($this->data['auth_google'] ?? false),
            'github' => (bool) ($this->data['auth_github'] ?? false),
            'passkeys' => (bool) ($this->data['auth_passkeys'] ?? false),
        ];
    }

    public function setProjectType(string $type): void
    {
        if ($type === 'consumer') {
            $this->data['project_type'] = $type;
        }
    }

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        if (($data['project_type'] ?? 'consumer') !== 'consumer') {
            throw ValidationException::withMessages([
                'project_type' => 'B2B applications are coming soon. Select Consumer to continue.',
            ]);
        }

        $tenant = Filament::getTenant() ?? Tenant::query()->findOrFail($data['tenant_id'] ?? null);
        if (! $tenant instanceof Tenant) {
            abort(403);
        }

        return app(ApplicationProvisioner::class)->create($tenant, [
            'name' => $data['name'] ?? null,
        ]);
    }

    #[\Override]
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
