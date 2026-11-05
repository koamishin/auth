<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\TenantApplication;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class ViewTenantApplication extends ViewRecord
{
    protected static string $resource = TenantApplicationResource::class;

    protected string $view = 'filament.resources.tenant-applications.pages.view-application';

    /** @var array<string, array{publishable_key: string, secret_key: string}>|null */
    #[Locked]
    public ?array $newApplicationCredentials = null;

    /** @var array{environment: string, publishable_key: string, secret_key: string, client_id: string, secret: string}|null */
    public ?array $rotatedSecretKey = null;

    /** @var array{environment: string, client_id: string, secret: string}|null */
    public ?array $regeneratedSecret = null;

    #[\Override]
    #[Unexposed]
    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ((int) session('new_app_credentials_expires_at', 0) < now()->timestamp) {
            session()->forget(['new_app_credentials', 'new_app_credentials_application_id', 'new_app_credentials_expires_at']);

            return;
        }

        if (session('new_app_credentials_application_id') !== $this->getRecord()->getKey()) {
            return;
        }

        $credentials = session()->pull('new_app_credentials', []);
        session()->forget(['new_app_credentials_application_id', 'new_app_credentials_expires_at']);

        $this->newApplicationCredentials = collect($credentials)
            ->map(fn (array $credential): array => [
                'publishable_key' => $credential['publishable_key'],
                'secret_key' => $credential['secret_key'],
            ])->all();
    }

    public function dismissNewCredentials(): void
    {
        $this->newApplicationCredentials = null;
    }

    #[On('quickstart-connect')]
    public function openQuickstartConnection(string $framework): void
    {
        TenantApplicationResource::authorizeEdit($this->getRecord());
        $this->mountAction('connectApplication', ['framework' => $framework]);
    }

    #[On('application-connected')]
    public function refreshApplicationState(): void
    {
        $this->getRecord()->refresh();
    }

    public function rotateSecretKey(): void
    {
        /** @var TenantApplication $app */
        $app = $this->getRecord();
        TenantApplicationResource::authorizeEdit($app);

        try {
            $this->rotatedSecretKey = app(ApplicationProvisioner::class)->rotateSecretKey($app, $app->environment);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Secret Key Rotated')
            ->body('A new environment secret key (sk_...) has been generated. Copy it now—it will not be shown again.')
            ->success()
            ->send();
    }

    public function regenerateSecret(): void
    {
        /** @var TenantApplication $app */
        $app = $this->getRecord();
        TenantApplicationResource::authorizeEdit($app);

        try {
            $this->regeneratedSecret = app(ApplicationProvisioner::class)->rotateLegacyClientSecret($app, $app->environment);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Client Secret Rotated')
            ->body('A new OAuth client secret has been generated. Copy it now—your sk_... secret key remains unchanged.')
            ->success()
            ->send();
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
                ->modalDescription(fn (): string => 'Tailored integration steps and code snippets for the ' . ucfirst($this->getRecord()->environment) . ' environment')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.resources.tenant-applications.components.quickstart-modal-content', [
                    'record' => $this->getRecord(),
                ])),
            Action::make('connectApplication')
                ->label('Connect your app')
                ->icon('heroicon-m-link')
                ->color('primary')
                ->fillForm(function (array $arguments): array {
                    /** @var TenantApplication $application */
                    $application = $this->getRecord();
                    $config = $application->getEnvironmentConfig();
                    $requestedFramework = $arguments['framework'] ?? null;
                    $framework = is_string($requestedFramework) && in_array($requestedFramework, ApplicationProvisioner::SUPPORTED_FRAMEWORKS, true)
                        ? $requestedFramework
                        : ($application->framework ?? 'nextjs');

                    return [
                        'framework' => $framework,
                        'client_type' => $config->client_type ?? ApplicationProvisioner::defaultClientTypeForFramework($framework) ?? 'public',
                        'redirect_uris' => $config->redirect_uris,
                    ];
                })
                ->schema([
                    Select::make('framework')
                        ->label('Platform / Framework')
                        ->options([
                            'nextjs' => 'Next.js (React)',
                            'react' => 'React (Vite / SPA)',
                            'vue' => 'Vue 3 / Nuxt',
                            'vanilla' => 'Vanilla JavaScript',
                            'mobile' => 'Mobile / Native (PKCE)',
                            'laravel' => 'Laravel (PHP / Inertia)',
                            'nodejs' => 'Node.js (Express / Fastify)',
                        ])
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            /** @var TenantApplication $application */
                            $application = $this->getRecord();
                            $config = $application->getEnvironmentConfig();
                            if ($config->oauthClient === null && $state !== null) {
                                $set('client_type', ApplicationProvisioner::defaultClientTypeForFramework($state) ?? 'public');
                            }
                        })
                        ->required(),
                    Radio::make('client_type')
                        ->label('Application architecture')
                        ->options([
                            'public' => 'Browser or mobile (public client + PKCE + publishable key)',
                            'confidential' => 'Server (confidential client + publishable & secret key)',
                        ])
                        ->descriptions([
                            'public' => 'Uses pk_test_... / pk_live_... with S256 PKCE. Never embed secret keys in browser or mobile apps.',
                            'confidential' => 'Authenticates server-side token exchanges and Management API requests with sk_test_... / sk_live_....',
                        ])
                        ->required(),
                    TagsInput::make('redirect_uris')
                        ->label('Allowed Callback URLs')
                        ->placeholder('Add callback URL and press Enter')
                        ->required()
                        ->helperText('Register exact callback URLs. Production URLs must use HTTPS.'),
                ])
                ->action(function (array $data): void {
                    /** @var TenantApplication $application */
                    $application = $this->getRecord();
                    TenantApplicationResource::authorizeEdit($application);
                    app(ApplicationProvisioner::class)->connectEnvironment($application, $application->environment, $data);
                    $application->refresh();
                    Notification::make()->title('Application connected')->success()->send();
                }),
            Action::make('rotateSecretKey')
                ->label('Rotate Secret Key')
                ->icon('heroicon-m-key')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Rotate Environment Secret Key')
                ->modalDescription('Generating a new secret key immediately invalidates the existing sk_... key for the active environment only. Other environments and legacy OAuth client secrets remain unchanged.')
                ->modalSubmitActionLabel('Yes, Rotate Secret Key')
                ->action(fn () => $this->rotateSecretKey()),
            Action::make('regenerateSecret')
                ->label('Rotate Legacy OAuth Secret')
                ->icon('heroicon-m-arrow-path')
                ->color('gray')
                ->visible(function (): bool {
                    /** @var TenantApplication $application */
                    $application = $this->getRecord();

                    return $application->getEnvironmentConfig()->oauthClient?->confidential() === true;
                })
                ->requiresConfirmation()
                ->modalHeading('Rotate Legacy OAuth Client Secret')
                ->modalDescription('Generates a new Passport OAuth client_secret for the active environment without invalidating your sk_... secret key.')
                ->modalSubmitActionLabel('Yes, Rotate OAuth Secret')
                ->action(fn () => $this->regenerateSecret()),
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
