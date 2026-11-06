<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

class EditTenantApplication extends EditRecord
{
    protected static string $resource = TenantApplicationResource::class;

    protected string $view = 'filament.resources.tenant-applications.pages.edit-application';

    public function getTitle(): string
    {
        return 'Settings';
    }

    public function getHeading(): string
    {
        return 'Application Settings';
    }

    public function selectEnvironment(string $environment): void
    {
        if (! in_array($environment, ['development', 'production'], true)) {
            return;
        }

        $this->data['environment'] = $environment;
        $record = $this->getRecord();
        if ($record instanceof TenantApplication) {
            foreach (TenantApplicationResource::environmentFormValues($record, $environment) as $name => $value) {
                $this->data[$name] = $value;
            }
        }
    }

    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        if ($record instanceof TenantApplication) {
            $data = [...$data, ...TenantApplicationResource::environmentFormValues($record, $record->environment)];
        }

        return $data;
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TenantApplication $record */
        $redirectUris = is_array($data['redirect_uris'] ?? null) ? array_values(array_filter($data['redirect_uris'], fn ($u): bool => is_string($u) && trim($u) !== '')) : [];
        if ($redirectUris !== []) {
            app(ApplicationProvisioner::class)->validateRedirects($data['environment'], $redirectUris);
        }
        $data['redirect_uris'] = $redirectUris;

        $clientType = $data['client_type'] ?? null;
        if ($clientType !== null && ! in_array($clientType, ['public', 'confidential'], true)) {
            throw ValidationException::withMessages(['client_type' => 'Select a valid client security profile.']);
        }

        if (! is_bool($data['allow_signups'] ?? null)) {
            throw ValidationException::withMessages(['allow_signups' => 'Choose whether self-service signup is allowed.']);
        }
        if (! is_bool($data['sso_enabled'] ?? null)) {
            throw ValidationException::withMessages(['sso_enabled' => 'Choose whether single sign-on is enabled.']);
        }

        return DB::transaction(function () use ($record, $data, $clientType): Model {
            $configuration = $record->environments()->where('environment', $data['environment'])->firstOrFail();
            $client = $configuration->oauthClient;

            if ($configuration->oauth_client_id !== null && (! $client instanceof Client || $client->getAttribute('revoked'))) {
                abort(409, 'The selected environment has no active OAuth client.');
            }

            if ($client instanceof Client && TenantApplicationEnvironment::query()->where('oauth_client_id', $client->getKey())->count() !== 1) {
                abort(409, 'The OAuth client is shared by multiple environments and cannot be changed.');
            }

            if ($client instanceof Client && $configuration->redirect_uris !== [] && $data['redirect_uris'] === []) {
                throw ValidationException::withMessages(['redirect_uris' => 'A connected application must retain at least one callback URL.']);
            }

            $resolvedClientType = $clientType;

            if ($client instanceof Client) {
                if ($clientType !== null && $client->confidential() !== ($clientType === 'confidential')) {
                    throw ValidationException::withMessages(['client_type' => 'Changing an existing OAuth client type would break connected applications.']);
                }
                $client->forceFill(['redirect_uris' => $data['redirect_uris']])->save();
            } elseif ($data['redirect_uris'] !== []) {
                if (! in_array($resolvedClientType, ['public', 'confidential'], true)) {
                    throw ValidationException::withMessages(['client_type' => 'Select a valid client security profile before registering callback URLs.']);
                }
                $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
                    name: $record->name.' ('.ucfirst($data['environment']).')',
                    redirectUris: $data['redirect_uris'],
                    confidential: $resolvedClientType === 'confidential',
                );
            }
            $settings = $configuration->settings ?? [];
            $settings['allow_signups'] = $data['allow_signups'];
            foreach ([
                'oauth_consent_enabled' => false,
                'sso_enabled' => false,
            ] as $key => $default) {
                $value = $data[$key] ?? $default;
                if ($value === $default || $value === '') {
                    unset($settings[$key]);
                } else {
                    $settings[$key] = $value;
                }
                unset($data[$key]);
            }

            $envUpdates = [
                'oauth_client_id' => $client instanceof Client ? (string) $client->getKey() : null,
                'redirect_uris' => $data['redirect_uris'],
                'settings' => $settings,
            ];
            if ($resolvedClientType !== null && $client instanceof Client) {
                $envUpdates['client_type'] = $resolvedClientType;
            }
            $configuration->update($envUpdates);
            unset($data['allow_signups'], $data['client_type']);
            $data['oauth_client_id'] = $client instanceof Client ? (string) $client->getKey() : null;
            $record->update($data);

            return $record;
        });
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('openSetupGuide')
                ->label('Setup Guide')
                ->icon('heroicon-m-question-mark-circle')
                ->color('gray')
                ->slideOver()
                ->modalWidth('5xl')
                ->modalHeading(fn (): string => 'Setup Guide — '.$this->getRecord()->name)
                ->modalDescription(fn (): string => 'Tailored integration steps and code snippets for the '.ucfirst($this->data['environment'] ?? $this->getRecord()->environment).' environment')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn (): Factory|\Illuminate\Contracts\View\View => view('filament.resources.tenant-applications.components.quickstart-modal-content', [
                    'record' => $this->getRecord(),
                ])),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
