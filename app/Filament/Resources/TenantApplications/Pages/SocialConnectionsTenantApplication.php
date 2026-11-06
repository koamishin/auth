<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Enums\SocialLoginProvider;
use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\TenantApplication;
use App\Services\OAuth\ApplicationSocialConnections;
use App\Settings\SocialLoginSettings;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class SocialConnectionsTenantApplication extends EditRecord
{
    protected static string $resource = TenantApplicationResource::class;

    protected string $view = 'filament.resources.tenant-applications.pages.edit-application-social-connections';

    public function getTitle(): string
    {
        return 'Social connections';
    }

    public function getHeading(): string
    {
        return 'Social connections';
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
    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                ToggleButtons::make('environment')
                    ->label('Environment')
                    ->options([
                        'development' => 'Development',
                        'production' => 'Production',
                    ])
                    ->colors([
                        'development' => 'warning',
                        'production' => 'success',
                    ])
                    ->default('development')
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set, ?TenantApplication $record): void {
                        if ($record instanceof TenantApplication) {
                            foreach (TenantApplicationResource::environmentFormValues($record, $state) as $name => $value) {
                                $set($name, $value);
                            }
                        }
                    })
                    ->inline()
                    ->required(),

                ...array_map($this->socialConnectionSection(...), SocialLoginProvider::cases()),
            ]);
    }

    private function socialConnectionSection(SocialLoginProvider $provider): Section
    {
        $prefix = 'social_'.$provider->value;

        return Section::make($provider->label())
            ->icon('kms-'.$provider->value)
            ->description(fn (): string => $provider->description())
            ->contained(false)
            ->components([
                ViewField::make($prefix.'_mode')
                    ->view('filament.forms.social-connection-picker')
                    ->viewData(fn (): array => [
                        'prefix' => $prefix,
                        'provider' => $provider->value,
                        'label' => $provider->label(),
                        'portalEnabled' => app(SocialLoginSettings::class)->isProviderEnabled($provider),
                    ]),

                TextInput::make($prefix.'_client_id')
                    ->label('Client ID')
                    ->maxLength(255)
                    ->required()
                    ->visible(fn (Get $get): bool => $get($prefix.'_mode') === 'custom'),

                TextInput::make($prefix.'_client_secret')
                    ->label('Client secret')
                    ->password()
                    ->revealable()
                    ->maxLength(2048)
                    ->helperText('Leave blank to keep the current secret. Changing the client ID requires a new secret.')
                    ->visible(fn (Get $get): bool => $get($prefix.'_mode') === 'custom'),

                TextInput::make($prefix.'_callback')
                    ->label('Provider callback URL')
                    ->helperText('Register this URL as the redirect URI in the '.$provider->label().' developer console.')
                    ->default(rtrim((string) config('app.url'), '/').$provider->defaultRedirectPath())
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (Get $get): bool => $get($prefix.'_mode') === 'custom'),
            ]);
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TenantApplication $record */
        $configuration = $record->environments()->where('environment', $data['environment'])->firstOrFail();
        $settings = $configuration->settings ?? [];
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

            unset($data[$prefix.'_mode'], $data[$prefix.'_client_id'], $data[$prefix.'_client_secret'], $data[$prefix.'_callback']);
        }

        if ($connections === []) {
            unset($settings['social_providers']);
        } else {
            $settings['social_providers'] = $connections;
        }

        $configuration->update(['settings' => $settings]);

        return $record;
    }

    protected function afterSave(): void
    {
        foreach (SocialLoginProvider::cases() as $provider) {
            $this->data['social_'.$provider->value.'_client_secret'] = null;
        }
    }

    /**
     * Effective connections for the environment being edited, so the page can
     * show which providers users will actually see.
     *
     * @return array<string, array{mode: string, resolved: bool}>
     */
    public function connectionStates(): array
    {
        $record = $this->getRecord();
        $environment = $record->environments()->where('environment', $this->data['environment'] ?? $record->environment)->first();
        $connections = app(ApplicationSocialConnections::class);

        return collect(SocialLoginProvider::cases())
            ->mapWithKeys(fn (SocialLoginProvider $provider): array => [
                $provider->value => [
                    'mode' => $this->data['social_'.$provider->value.'_mode'] ?? 'inherit',
                    'resolved' => $connections->credentials($provider, $environment) !== null,
                ],
            ])
            ->all();
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => route('tenant-applications.appearance.preview', [
                    'tenant' => $this->getRecord()->tenant,
                    'application' => $this->getRecord(),
                    'environment' => $this->data['environment'] ?? $this->getRecord()->environment,
                ]), shouldOpenInNewTab: true),
            ViewAction::make(),
        ];
    }
}
