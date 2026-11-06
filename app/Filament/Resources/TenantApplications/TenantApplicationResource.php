<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications;

use App\Enums\SocialLoginProvider;
use App\Filament\Resources\TenantApplications\Pages\AppearanceTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\AuthenticationTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\CreateTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\EditTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\ListApplicationUsers;
use App\Filament\Resources\TenantApplications\Pages\ListTenantApplications;
use App\Filament\Resources\TenantApplications\Pages\SocialConnectionsTenantApplication;
use App\Filament\Resources\TenantApplications\Pages\ViewApplicationUser;
use App\Filament\Resources\TenantApplications\Pages\ViewTenantApplication;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Layout\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantApplicationResource extends Resource
{
    protected static ?string $model = TenantApplication::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static \UnitEnum|string|null $navigationGroup = 'Applications';

    protected static ?string $navigationLabel = 'Applications';

    protected static ?string $modelLabel = 'Application';

    protected static ?string $pluralModelLabel = 'Applications';

    protected static ?int $navigationSort = 1;

    protected static bool $isScopedToTenant = true;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Select a project type')
                    ->description('Choose how authentication is structured for this application.')
                    ->icon('heroicon-o-rectangle-stack')
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->components([
                        Select::make('tenant_id')
                            ->label('Organization')
                            ->relationship('tenant', 'name')
                            ->default(fn (): mixed => Filament::getTenant()?->getKey())
                            ->disabled(fn (): bool => Filament::getTenant() !== null)
                            ->hidden(fn (): bool => Filament::getTenant() !== null)
                            ->dehydrated()
                            ->searchable()
                            ->preload(),

                        Radio::make('project_type')
                            ->label('Project Type')
                            ->options([
                                'consumer' => 'Consumer',
                                'b2b' => 'B2B — coming soon',
                            ])
                            ->descriptions([
                                'consumer' => 'Designed for individual end users.',
                                'b2b' => 'Designed for teams with roles and permissions.',
                            ])
                            ->default('consumer')
                            ->columns(2)
                            ->disableOptionWhen(fn (string $value): bool => $value === 'b2b')
                            ->live(),
                    ]),

                Section::make(fn (string $operation): string => $operation === 'create' ? 'Authentication' : 'General Information')
                    ->id('general')
                    ->description(fn (string $operation): string => $operation === 'create' ? 'Application name and user sign-in options.' : 'Application name, description, and core branding identity.')
                    ->icon(fn (string $operation): string => $operation === 'create' ? 'heroicon-o-shield-check' : 'heroicon-o-adjustments-horizontal')
                    ->contained(fn (string $operation): bool => $operation === 'create')
                    ->columns(fn (string $operation): array => $operation === 'create' ? ['default' => 2] : ['default' => 1])
                    ->components([
                        TextInput::make('name')
                            ->label('Application name')
                            ->helperText('Branded name displayed on hosted login and registration screens.')
                            ->default('My Application')
                            ->placeholder('e.g. My Application')
                            ->prefixIcon('heroicon-o-tag')
                            ->required()
                            ->maxLength(255)
                            ->live(debounce: 300),

                        Textarea::make('description')
                            ->label('Description / Project Purpose')
                            ->placeholder('Optional notes on what this application is for and who accesses it...')
                            ->rows(2)
                            ->maxLength(500)
                            ->visible(fn (?TenantApplication $record): bool => $record instanceof TenantApplication),

                        Section::make('Sign in options')
                            ->description('Email sign-in is available. Configuring sign-in methods here is coming soon.')
                            ->visible(fn (string $operation): bool => $operation === 'create')
                            ->components([
                                Toggle::make('auth_email')
                                    ->label('Email')
                                    ->default(true)
                                    ->disabled()
                                    ->live(),
                                Toggle::make('auth_phone')
                                    ->label('Phone number — coming soon')
                                    ->default(false)
                                    ->disabled()
                                    ->live(),
                                Toggle::make('auth_username')
                                    ->label('Username — coming soon')
                                    ->default(false)
                                    ->disabled()
                                    ->live(),
                                Toggle::make('auth_google')
                                    ->label('Google — configuration coming soon')
                                    ->default(false)
                                    ->disabled()
                                    ->live(),
                                Toggle::make('auth_github')
                                    ->label('GitHub — configuration coming soon')
                                    ->default(false)
                                    ->disabled()
                                    ->live(),
                                Toggle::make('auth_passkeys')
                                    ->label('Passkeys — configuration coming soon')
                                    ->default(false)
                                    ->disabled()
                                    ->live(),
                            ])
                            ->columns([
                                'default' => 2,
                                'sm' => 3,
                            ]),
                    ]),

                Section::make('Target Architecture & Framework')
                    ->id('target-architecture')
                    ->contained(false)
                    ->description('Platform preset and OAuth client security profile.')
                    ->icon('heroicon-o-cpu-chip')
                    ->visible(fn (?TenantApplication $record): bool => $record instanceof TenantApplication)
                    ->collapsible()
                    ->components([
                        ToggleButtons::make('framework')
                            ->label('Platform / SDK Preset')
                            ->options([
                                'nextjs' => 'Next.js (React)',
                                'react' => 'React (Vite / SPA)',
                                'vue' => 'Vue 3 / Nuxt',
                                'vanilla' => 'Vanilla JavaScript',
                                'mobile' => 'Mobile / Native',
                                'laravel' => 'Laravel (PHP / Inertia)',
                                'nodejs' => 'Node.js (Backend)',
                            ])
                            ->icons([
                                'nextjs' => 'kms-nextjs',
                                'react' => 'kms-reactjs',
                                'vue' => 'kms-vuejs',
                                'vanilla' => 'kms-javascript',
                                'mobile' => 'heroicon-o-device-phone-mobile',
                                'laravel' => 'kms-laravel',
                                'nodejs' => 'kms-nodejs',
                            ])
                            ->columns([
                                'default' => 1,
                                'sm' => 2,
                                'lg' => 3,
                            ])
                            ->gridDirection('row')
                            ->live()
                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                if ($state !== null && $state !== '') {
                                    $set('client_type', in_array($state, ['laravel', 'nodejs'], true) ? 'confidential' : 'public');
                                }
                            }),

                        Radio::make('client_type')
                            ->label('Client Security Profile (Selected Environment)')
                            ->options([
                                'public' => 'Browser / Mobile App (Public + PKCE)',
                                'confidential' => 'Server / Backend App (Confidential Secret)',
                            ])
                            ->descriptions([
                                'public' => 'Authenticates with your Publishable Key (pk_...) and mandatory S256 PKCE. Never embed secret keys in browser or mobile apps.',
                                'confidential' => 'Authenticates code exchanges on your backend using your Publishable Key (pk_...) and Secret Key (sk_...).',
                            ])
                            ->columns(1),
                    ]),

                Section::make('Callbacks & sign-up')
                    ->id('environment-callbacks')
                    ->contained(false)
                    ->columns(1)
                    ->description('Whitelisted redirect callback URLs and self-service signup for the environment selected above.')
                    ->icon('heroicon-o-globe-alt')
                    ->visible(fn (?TenantApplication $record): bool => $record instanceof TenantApplication)
                    ->collapsible()
                    ->components([
                        // The environment is chosen from the page header (and the topbar
                        // context selector); it stays in the form state so it dehydrates.
                        Hidden::make('environment')
                            ->default('development')
                            ->required(),

                        Toggle::make('allow_signups')
                            ->label('Allow self-service user signup')
                            ->helperText('Allow new and existing users to join this application during signup or consent. Turn off to require administrator invitation.')
                            ->default(fn (Get $get): bool => $get('environment') === 'development')
                            ->rules(['boolean']),

                        TagsInput::make('redirect_uris')
                            ->label('Allowed Callback URLs (Redirect URIs)')
                            ->helperText('Whitelisted URLs that the portal will redirect back to after signing in. Must be HTTPS in production.')
                            ->placeholder('Add callback URL and press Enter')
                            ->default([]),
                    ]),
                Section::make('Sign-in behaviour')
                    ->id('sign-in-behaviour')
                    ->contained(false)
                    ->description('OAuth consent and single sign-on apply only to the selected environment. Identifiers, verification, and passkeys are configured under Authentication.')
                    ->icon('heroicon-o-shield-check')
                    ->visible(fn (?TenantApplication $record): bool => $record instanceof TenantApplication)
                    ->components([
                        Toggle::make('oauth_consent_enabled')
                            ->label('Require OAuth consent screen')
                            ->helperText('Ask users to confirm granting access to their profile and scopes. Leave off for seamless first-party login without a permission prompt.')
                            ->default(false),
                        Toggle::make('sso_enabled')->label('Allow single sign-on')->default(false)
                            ->helperText('Share sign-in with other opted-in applications in this organization and environment for up to eight hours. Each application still checks membership and consent.'),
                    ])->columns(1),
            ]);
    }

    /** @return array<string, mixed> */
    public static function environmentFormValues(?TenantApplication $application, ?string $environment): array
    {
        /** @var TenantApplicationEnvironment|null $config */
        $config = $application?->environments()->where('environment', $environment)->first();
        $values = [
            'redirect_uris' => $config instanceof TenantApplicationEnvironment ? ($config->redirect_uris ?? []) : [],
            'client_type' => $config?->client_type,
            'allow_signups' => $application instanceof TenantApplication ? $config?->getSetting('allow_signups', false) === true : $environment === 'development',
            'email_verification_policy' => match ($config?->getSetting('email_verification_required')) {
                true => 'required',
                false => 'disabled',
                default => 'inherit',
            },
            'passkeys_enabled' => $config?->getSetting('passkeys_enabled', true) === true,
            'oauth_consent_enabled' => $config?->getSetting('oauth_consent_enabled', false) === true,
            'sso_enabled' => $config?->getSetting('sso_enabled', false) === true,
            'auth_email_signup' => $config?->getSetting('auth_email_signup', true) === true,
            'auth_email_signin' => $config?->getSetting('auth_email_signin', true) === true,
            'email_verification_method' => $config?->getSetting('email_verification_method', 'link') ?? 'link',
            'auth_password_enabled' => $config?->getSetting('auth_password_enabled', true) === true,
            'auth_password_min_length' => (int) $config?->getSetting('auth_password_min_length', 8),
            'passkey_platform_authenticators' => $config?->getSetting('passkey_platform_authenticators', true) === true,
            'passkey_require_user_verification' => $config?->getSetting('passkey_require_user_verification', true) === true,
            'user_metadata_attributes' => $config?->userMetadataAttributes() ?? [],
            'user_metadata_defaults' => $config?->getSetting('user_metadata_defaults', []) ?? [],
            'auth_phone_signup' => false,
            'auth_phone_signin' => false,
            'auth_phone_verification' => false,
            'auth_username_signup' => false,
            'auth_username_signin' => false,
            'login_title' => $config?->getSetting('login_title'),
            'login_description' => $config?->getSetting('login_description'),
            'login_logo_url' => $config?->getSetting('login_logo_url'),
            'auth_layout' => $config?->getSetting('auth_layout', 'inherit') ?? 'inherit',
            'auth_color_theme' => $config?->getSetting('auth_color_theme', 'inherit') ?? 'inherit',
            'auth_background' => $config?->getSetting('auth_background', 'inherit') ?? 'inherit',
        ];
        $connections = $config?->getSetting('social_providers', []);
        foreach (SocialLoginProvider::cases() as $provider) {
            $connection = is_array($connections) ? ($connections[$provider->value] ?? []) : [];
            $values['social_'.$provider->value.'_mode'] = is_array($connection) ? ($connection['mode'] ?? 'inherit') : 'inherit';
            $values['social_'.$provider->value.'_client_id'] = is_array($connection) ? ($connection['client_id'] ?? null) : null;
            $values['social_'.$provider->value.'_client_secret'] = null;
        }

        return $values;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application Credentials & Details')
                    ->components([
                        TextEntry::make('tenant.name')
                            ->label('Organization')
                            ->weight('bold'),

                        TextEntry::make('name')
                            ->label('Application Name'),

                        TextEntry::make('environment')
                            ->label('Environment')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'production' => 'success',
                                default => 'warning',
                            }),

                        TextEntry::make('framework')
                            ->label('Framework')
                            ->badge()
                            ->formatStateUsing(fn (TenantApplication $record): string => $record->getFrameworkLabel())
                            ->color('info'),

                        TextEntry::make('publishable_key')
                            ->label('Publishable Key')
                            ->state(fn (TenantApplication $record): string => $record->getPublishableKey())
                            ->fontFamily('mono')
                            ->copyable(),

                        TextEntry::make('oauth_client_id')
                            ->label('Legacy OAuth Client ID')
                            ->fontFamily('mono')
                            ->copyable(),

                        TextEntry::make('redirect_uris')
                            ->label('Redirect URIs')
                            ->badge()
                            ->separator(','),
                    ])->columns(2),

                Section::make('Setup Guide (Clerk Workflow)')
                    ->description('Follow these tailored steps to connect your application to Koamishin Auth')
                    ->components([
                        TextEntry::make('install_command')
                            ->label('1. Install SDK Package')
                            ->state(fn (TenantApplication $record): string => $record->getInstallCommand())
                            ->fontFamily('mono')
                            ->copyable(),

                        TextEntry::make('env_snippet')
                            ->label('2. Environment Variables (.env)')
                            ->state(fn (TenantApplication $record): string => $record->getEnvSnippet())
                            ->fontFamily('mono')
                            ->copyable(),

                        TextEntry::make('boilerplate_code')
                            ->label('3. Quickstart Integration Code')
                            ->state(fn (TenantApplication $record): string => $record->getSetupGuideCode())
                            ->fontFamily('mono')
                            ->copyable(),
                    ])->columns(1),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->contentGrid([
                'default' => 1,
                'md' => 2,
                'xl' => 3,
                '2xl' => 4,
            ])
            ->recordUrl(fn (TenantApplication $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                View::make('filament.tables.columns.application-card'),
                TextColumn::make('name')
                    ->searchable()
                    ->hidden(),
                TextColumn::make('framework')
                    ->searchable()
                    ->hidden(),
            ])
            ->filters([
                SelectFilter::make('environment')
                    ->options([
                        'development' => 'Development',
                        'production' => 'Production',
                    ]),

                SelectFilter::make('framework')
                    ->options([
                        'laravel' => 'Laravel (PHP)',
                        'nextjs' => 'Next.js',
                        'vue' => 'Vue / Nuxt',
                        'react' => 'React',
                        'nodejs' => 'Node.js',
                        'vanilla' => 'Vanilla JS',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTenantApplications::route('/'),
            'create' => CreateTenantApplication::route('/create'),
            'view' => ViewTenantApplication::route('/{record}'),
            'edit' => EditTenantApplication::route('/{record}/edit'),
            'appearance' => AppearanceTenantApplication::route('/{record}/appearance'),
            'social-connections' => SocialConnectionsTenantApplication::route('/{record}/social-connections'),
            'authentication' => AuthenticationTenantApplication::route('/{record}/authentication'),
            'users' => ListApplicationUsers::route('/{record}/users'),
            'user' => ViewApplicationUser::route('/{record}/users/{userId}'),
        ];
    }
}
