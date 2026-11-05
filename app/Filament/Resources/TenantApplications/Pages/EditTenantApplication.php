<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Enums\SocialLoginProvider;
use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

class EditTenantApplication extends EditRecord
{
    protected static string $resource = TenantApplicationResource::class;

    protected string $view = 'filament.resources.tenant-applications.pages.edit-application';

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
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (SocialLoginProvider::cases() as $provider) {
            $key = 'social_'.$provider->value.'_client_secret';
            if (is_string($data[$key] ?? null) && $data[$key] !== '') {
                $data[$key] = Crypt::encryptString($data[$key]);
            }
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
        if (! in_array($data['auth_color_theme'] ?? 'inherit', ['inherit', ...array_keys(TenantApplicationEnvironment::AUTH_COLOR_THEMES)], true)) {
            throw ValidationException::withMessages(['auth_color_theme' => 'Choose a valid login color theme.']);
        }
        $verificationPolicy = $data['email_verification_policy'] ?? null;
        if (! in_array($verificationPolicy, ['inherit', 'required', 'disabled'], true)) {
            throw ValidationException::withMessages(['email_verification_policy' => 'Choose a valid email verification policy.']);
        }
        foreach (['passkeys_enabled', 'sso_enabled'] as $feature) {
            if (! is_bool($data[$feature] ?? null)) {
                throw ValidationException::withMessages([$feature => 'Choose whether this feature is enabled.']);
            }
        }

        return DB::transaction(function () use ($record, $data, $verificationPolicy, $clientType): Model {
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
            if ($verificationPolicy === 'inherit') {
                unset($settings['email_verification_required']);
            } else {
                $settings['email_verification_required'] = $verificationPolicy === 'required';
            }
            unset($data['email_verification_policy']);
            foreach ([
                'passkeys_enabled' => true,
                'oauth_consent_enabled' => false,
                'sso_enabled' => false,
                'login_title' => null,
                'login_description' => null,
                'login_logo_url' => null,
                'auth_layout' => 'inherit',
                'auth_color_theme' => 'inherit',
            ] as $key => $default) {
                $value = $data[$key] ?? $default;
                if ($value === $default || $value === '') {
                    unset($settings[$key]);
                } else {
                    $settings[$key] = $value;
                }
                unset($data[$key]);
            }

            $connections = is_array($settings['social_providers'] ?? null) ? $settings['social_providers'] : [];
            foreach (SocialLoginProvider::cases() as $provider) {
                $prefix = 'social_'.$provider->value;
                $mode = $data[$prefix.'_mode'] ?? 'inherit';
                if (! in_array($mode, ['inherit', 'disabled', 'custom'], true)) {
                    throw ValidationException::withMessages([$prefix.'_mode' => 'Select a valid social connection.']);
                }

                if ($mode === 'inherit') {
                    unset($connections[$provider->value]);
                } elseif ($mode === 'disabled') {
                    $connections[$provider->value] = ['mode' => 'disabled'];
                } else {
                    $old = $connections[$provider->value] ?? [];
                    $clientId = $data[$prefix.'_client_id'] ?? null;
                    $secret = $data[$prefix.'_client_secret'] ?? null;
                    if (! is_string($clientId) || trim($clientId) === '') {
                        throw ValidationException::withMessages([$prefix.'_client_id' => 'Enter the provider client ID.']);
                    }
                    if (! is_string($secret) || $secret === '') {
                        $secret = is_array($old) && ($old['client_id'] ?? null) === $clientId ? ($old['client_secret'] ?? null) : null;
                    }
                    if (! is_string($secret) || $secret === '') {
                        throw ValidationException::withMessages([$prefix.'_client_secret' => 'Enter the provider client secret.']);
                    }
                    $connections[$provider->value] = ['mode' => 'custom', 'client_id' => $clientId, 'client_secret' => $secret];
                }
                unset($data[$prefix.'_mode'], $data[$prefix.'_client_id'], $data[$prefix.'_client_secret']);
            }
            if ($connections === []) {
                unset($settings['social_providers']);
            } else {
                $settings['social_providers'] = $connections;
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

    protected function afterSave(): void
    {
        foreach (SocialLoginProvider::cases() as $provider) {
            $this->data['social_'.$provider->value.'_client_secret'] = null;
        }
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
                ->modalHeading(fn (): string => 'Setup Guide — ' . $this->getRecord()->name)
                ->modalDescription(fn (): string => 'Tailored integration steps and code snippets for the ' . ucfirst($this->data['environment'] ?? $this->getRecord()->environment) . ' environment')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.resources.tenant-applications.components.quickstart-modal-content', [
                    'record' => $this->getRecord(),
                ])),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
