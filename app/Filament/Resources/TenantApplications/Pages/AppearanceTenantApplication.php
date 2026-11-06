<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AppearanceTenantApplication extends EditRecord
{
    protected static string $resource = TenantApplicationResource::class;

    protected string $view = 'filament.resources.tenant-applications.pages.edit-application-appearance';

    public function getTitle(): string
    {
        return 'Appearance';
    }

    public function getHeading(): string
    {
        return 'Hosted login appearance';
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
    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // The environment is chosen from the page header (and the topbar
                // context selector); it stays in the form state so it dehydrates.
                Hidden::make('environment')
                    ->default('development')
                    ->required(),

                Section::make('Brand & identity')
                    ->description('Branding shown on the Portal-hosted login and registration screens for this application.')
                    ->icon('heroicon-o-identification')
                    ->contained(false)
                    ->components([
                        TextInput::make('login_title')
                            ->label('Login heading')
                            ->placeholder('Welcome back to Acme')
                            ->maxLength(120),
                        Textarea::make('login_description')
                            ->label('Login description')
                            ->placeholder('Sign in to continue to your Acme workspace...')
                            ->rows(2)
                            ->maxLength(255),
                        TextInput::make('login_logo_url')
                            ->label('Logo URL')
                            ->url()
                            ->rules(['nullable', 'url:https'])
                            ->maxLength(2048)
                            ->helperText('Use an HTTPS image URL. Leave blank to use the Portal logo.'),
                    ]),

                Section::make('Layout')
                    ->description('How the hosted sign-in screen is arranged.')
                    ->icon('heroicon-o-squares-2x2')
                    ->contained(false)
                    ->components([
                        ViewField::make('auth_layout')
                            ->label('Layout style')
                            ->view('filament.forms.auth-layout-picker'),
                    ]),

                Section::make('Color theme')
                    ->description('Color palette applied to the hosted sign-in experience.')
                    ->icon('heroicon-o-swatch')
                    ->contained(false)
                    ->components([
                        ViewField::make('auth_color_theme')
                            ->label('Color theme')
                            ->view('filament.forms.auth-theme-picker'),
                    ]),

                Section::make('Background')
                    ->description('Decorative background treatment behind the sign-in screen.')
                    ->icon('heroicon-o-photo')
                    ->contained(false)
                    ->components([
                        ViewField::make('auth_background')
                            ->label('Background style')
                            ->view('filament.forms.auth-background-picker'),
                    ]),
            ]);
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TenantApplication $record */
        $layout = $data['auth_layout'] ?? 'inherit';
        if (! in_array($layout, ['inherit', 'simple', 'card', 'split'], true)) {
            throw ValidationException::withMessages(['auth_layout' => 'Choose a valid login layout.']);
        }

        $theme = $data['auth_color_theme'] ?? 'inherit';
        if (! in_array($theme, ['inherit', ...array_keys(TenantApplicationEnvironment::AUTH_COLOR_THEMES)], true)) {
            throw ValidationException::withMessages(['auth_color_theme' => 'Choose a valid login color theme.']);
        }

        $background = $data['auth_background'] ?? 'inherit';
        if (! in_array($background, ['inherit', ...array_keys(TenantApplicationEnvironment::AUTH_BACKGROUNDS)], true)) {
            throw ValidationException::withMessages(['auth_background' => 'Choose a valid background style.']);
        }

        $settings = [
            'login_title' => is_string($data['login_title'] ?? null) ? trim($data['login_title']) : null,
            'login_description' => is_string($data['login_description'] ?? null) ? trim($data['login_description']) : null,
            'login_logo_url' => is_string($data['login_logo_url'] ?? null) ? trim($data['login_logo_url']) : null,
            'auth_layout' => $layout,
            'auth_color_theme' => $theme,
            'auth_background' => $background,
        ];

        $configuration = $record->environments()->where('environment', $data['environment'])->firstOrFail();

        $configuration->update([
            'settings' => $this->syncSettings($configuration->settings ?? [], $settings),
        ]);

        return $record;
    }

    /**
     * Merge appearance values into the environment settings, clearing anything
     * that falls back to the portal default.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, string|null>  $values
     * @return array<string, mixed>
     */
    private function syncSettings(array $current, array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array($value, [null, '', 'inherit'], true)) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }

        return $current;
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
