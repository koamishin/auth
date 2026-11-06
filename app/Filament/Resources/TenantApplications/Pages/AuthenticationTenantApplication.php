<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Configures how users authenticate with the application: the identifiers they
 * may sign up and sign in with, email verification, password and passkey rules,
 * biometric preferences, and the shape of the application user model.
 */
class AuthenticationTenantApplication extends EditRecord
{
    protected static string $resource = TenantApplicationResource::class;

    protected string $view = 'filament.resources.tenant-applications.pages.edit-application-authentication';

    public function getTitle(): string
    {
        return 'User & authentication';
    }

    public function getHeading(): string
    {
        return 'User & authentication';
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

                Tabs::make('Authentication')
                    ->contained(false)
                    ->persistTabInQueryString()
                    ->tabs([
                        Tabs\Tab::make('Email')
                            ->icon('heroicon-o-envelope')
                            ->schema($this->emailTab()),
                        Tabs\Tab::make('Phone')
                            ->icon('heroicon-o-device-phone-mobile')
                            ->schema($this->phoneTab()),
                        Tabs\Tab::make('Username')
                            ->icon('heroicon-o-at-symbol')
                            ->schema($this->usernameTab()),
                        Tabs\Tab::make('Password')
                            ->icon('heroicon-o-key')
                            ->schema($this->passwordTab()),
                        Tabs\Tab::make('Passkeys')
                            ->icon('heroicon-o-finger-print')
                            ->schema($this->passkeysTab()),
                        Tabs\Tab::make('Biometric')
                            ->icon('heroicon-o-identification')
                            ->schema($this->biometricTab()),
                        Tabs\Tab::make('User model')
                            ->icon('heroicon-o-circle-stack')
                            ->schema($this->userModelTab()),
                    ]),
            ]);
    }

    /** @return array<int, Section> */
    private function emailTab(): array
    {
        return [
            Section::make('Sign-up with email')
                ->description('Allow users to sign up with their email address.')
                ->contained(false)
                ->components([
                    Toggle::make('auth_email_signup')
                        ->label('Allow email sign-up')
                        ->helperText('Turn off to require an invitation or another identifier before a user can be created.')
                        ->default(true)
                        ->rules(['boolean']),
                ]),

            Section::make('Email verification')
                ->description('Require users to verify their email address.')
                ->contained(false)
                ->components([
                    Select::make('email_verification_policy')
                        ->label('Verification policy')
                        ->options([
                            'inherit' => 'Use Portal default',
                            'required' => 'Required for this application',
                            'disabled' => 'Not required for this application',
                        ])
                        ->helperText('Choose whether this application requires a verified email, or use the Portal default.')
                        ->default('inherit')
                        ->required(),

                    ToggleButtons::make('email_verification_method')
                        ->label('Verification method')
                        ->options(TenantApplicationEnvironment::EMAIL_VERIFICATION_METHODS)
                        ->helperText('Additional channels, such as one-time codes, are not available yet.')
                        ->default('link')
                        ->inline()
                        ->visible(fn (Get $get): bool => $get('email_verification_policy') !== 'disabled'),
                ]),

            Section::make('Sign-in with email')
                ->description('Allow users to sign in with their email address.')
                ->contained(false)
                ->components([
                    Toggle::make('auth_email_signin')
                        ->label('Allow email sign-in')
                        ->helperText('Turn off when users must sign in with a passkey or another method.')
                        ->default(true)
                        ->rules(['boolean']),
                ]),
        ];
    }

    /** @return array<int, Section> */
    private function phoneTab(): array
    {
        return [
            Section::make('Phone number')
                ->description('Phone number identifiers are not supported by this portal yet. The settings below are placeholders and are not enforced.')
                ->contained(false)
                ->components([
                    Toggle::make('auth_phone_signup')
                        ->label('Sign-up with phone number')
                        ->default(false)
                        ->disabled(),
                    Toggle::make('auth_phone_signin')
                        ->label('Sign-in with phone number')
                        ->default(false)
                        ->disabled(),
                    Toggle::make('auth_phone_verification')
                        ->label('Verify phone numbers with an SMS code')
                        ->default(false)
                        ->disabled(),
                ]),
        ];
    }

    /** @return array<int, Section> */
    private function usernameTab(): array
    {
        return [
            Section::make('Username')
                ->description('This portal identifies users by email address. Usernames are not supported yet, so the settings below are placeholders and are not enforced.')
                ->contained(false)
                ->components([
                    Toggle::make('auth_username_signup')
                        ->label('Sign-up with username')
                        ->default(false)
                        ->disabled(),
                    Toggle::make('auth_username_signin')
                        ->label('Sign-in with username')
                        ->default(false)
                        ->disabled(),
                ]),
        ];
    }

    /** @return array<int, Section> */
    private function passwordTab(): array
    {
        return [
            Section::make('Password')
                ->description('Let users authenticate with an email address and password.')
                ->contained(false)
                ->components([
                    Toggle::make('auth_password_enabled')
                        ->label('Allow password authentication')
                        ->helperText('Turning this off stops new passwords from being created — sign-up and password resets will require another method. Users who already have a password keep signing in with it, so nobody is locked out.')
                        ->default(true)
                        ->rules(['boolean']),

                    TextInput::make('auth_password_min_length')
                        ->label('Minimum password length')
                        ->numeric()
                        ->minValue(8)
                        ->maxValue(128)
                        ->default(8)
                        ->required()
                        ->helperText('Enforced whenever a password is created or changed in this environment. Always applied on top of the platform default.')
                        // Hidden while passwords are off, but the value is kept so
                        // re-enabling passwords restores the previous minimum.
                        ->dehydratedWhenHidden()
                        ->visible(fn (Get $get): bool => $get('auth_password_enabled') !== false),
                ]),
        ];
    }

    /** @return array<int, Section> */
    private function passkeysTab(): array
    {
        return [
            Section::make('Passkeys')
                ->description('Let users sign in without a password using a passkey (WebAuthn).')
                ->contained(false)
                ->components([
                    Toggle::make('passkeys_enabled')
                        ->label('Allow passkey sign-in')
                        ->helperText('Users can register a passkey and use it to sign in to this application.')
                        ->default(true)
                        ->rules(['boolean']),
                ]),
        ];
    }

    /** @return array<int, Section> */
    private function biometricTab(): array
    {
        return [
            Section::make('Biometric authenticators')
                ->description('Control which authenticators may be used to create passkeys, and whether a biometric or PIN check is required.')
                ->contained(false)
                ->components([
                    Toggle::make('passkey_platform_authenticators')
                        ->label('Allow built-in authenticators')
                        ->helperText('Permit Touch ID, Face ID, Windows Hello, and Android biometrics. Turn off to accept only external security keys.')
                        ->default(true)
                        ->rules(['boolean']),

                    Toggle::make('passkey_require_user_verification')
                        ->label('Require biometric or PIN verification')
                        ->helperText('Require the authenticator to verify the user rather than only testing for presence. Recommended.')
                        ->default(true)
                        ->rules(['boolean']),
                ]),
        ];
    }

    /** @return array<int, Section> */
    private function userModelTab(): array
    {
        return [
            Section::make('Metadata attributes')
                ->description('Define the custom attributes this application stores about each user. Public attributes are returned in tokens and the management API; private attributes are readable by your backend only.')
                ->contained(false)
                ->components([
                    Repeater::make('user_metadata_attributes')
                        ->hiddenLabel()
                        ->addActionLabel('Add attribute')
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state): ?string => $state['key'] ?? null)
                        ->components([
                            TextInput::make('key')
                                ->label('Attribute')
                                ->placeholder('e.g. plan')
                                ->required()
                                ->maxLength(64)
                                ->rules(['regex:/^[a-zA-Z][a-zA-Z0-9_]*$/']),

                            Select::make('type')
                                ->label('Type')
                                ->options(TenantApplicationEnvironment::METADATA_ATTRIBUTE_TYPES)
                                ->default('string')
                                ->required(),

                            Select::make('visibility')
                                ->label('Visibility')
                                ->options(TenantApplicationEnvironment::METADATA_ATTRIBUTE_VISIBILITIES)
                                ->default('public')
                                ->required(),
                        ])
                        ->columns(3),

                    KeyValue::make('user_metadata_defaults')
                        ->label('Default values')
                        ->keyLabel('Attribute')
                        ->valueLabel('Default value')
                        ->helperText('Applied to new users created in this environment.')
                        ->addActionLabel('Add default'),
                ]),
        ];
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TenantApplication $record */
        $configuration = $record->environments()->where('environment', $data['environment'])->firstOrFail();
        $settings = $configuration->settings ?? [];

        $verificationPolicy = $data['email_verification_policy'] ?? null;
        if (! in_array($verificationPolicy, ['inherit', 'required', 'disabled'], true)) {
            throw ValidationException::withMessages(['email_verification_policy' => 'Choose a valid email verification policy.']);
        }

        $verificationMethod = $data['email_verification_method'] ?? 'link';
        if (! array_key_exists($verificationMethod, TenantApplicationEnvironment::EMAIL_VERIFICATION_METHODS)) {
            throw ValidationException::withMessages(['email_verification_method' => 'Choose a valid verification method.']);
        }

        foreach ([
            'auth_email_signup' => true,
            'auth_email_signin' => true,
            'auth_password_enabled' => true,
            'passkeys_enabled' => true,
            'passkey_platform_authenticators' => true,
            'passkey_require_user_verification' => true,
        ] as $key => $default) {
            if (! is_bool($data[$key] ?? null)) {
                throw ValidationException::withMessages([$key => 'Choose whether this option is enabled.']);
            }

            $value = $data[$key];
            if ($value === $default) {
                unset($settings[$key]);
            } else {
                $settings[$key] = $value;
            }

            unset($data[$key]);
        }

        if ($verificationPolicy === 'inherit') {
            unset($settings['email_verification_required']);
        } else {
            $settings['email_verification_required'] = $verificationPolicy === 'required';
        }

        if ($verificationMethod === 'link') {
            unset($settings['email_verification_method']);
        } else {
            $settings['email_verification_method'] = $verificationMethod;
        }

        $minLength = (int) ($data['auth_password_min_length'] ?? 8);
        if ($minLength < 8 || $minLength > 128) {
            throw ValidationException::withMessages(['auth_password_min_length' => 'Choose a minimum length between 8 and 128.']);
        }

        if ($minLength === 8) {
            unset($settings['auth_password_min_length']);
        } else {
            $settings['auth_password_min_length'] = $minLength;
        }

        $attributes = $this->normalizeAttributes($data['user_metadata_attributes'] ?? []);
        if ($attributes === []) {
            unset($settings['user_metadata_attributes']);
        } else {
            $settings['user_metadata_attributes'] = $attributes;
        }

        $defaults = $data['user_metadata_defaults'] ?? [];
        if (! is_array($defaults) || $defaults === []) {
            unset($settings['user_metadata_defaults']);
        } else {
            $settings['user_metadata_defaults'] = $defaults;
        }

        unset(
            $data['email_verification_policy'],
            $data['email_verification_method'],
            $data['auth_password_min_length'],
            $data['user_metadata_attributes'],
            $data['user_metadata_defaults'],
        );

        $configuration->update(['settings' => $settings]);

        return $record;
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     * @return list<array{key: string, type: string, visibility: string}>
     */
    private function normalizeAttributes(array $attributes): array
    {
        $normalized = [];
        $seen = [];

        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $key = is_string($attribute['key'] ?? null) ? trim($attribute['key']) : '';

            if ($key === '') {
                continue;
            }

            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $key) !== 1) {
                throw ValidationException::withMessages(['user_metadata_attributes' => "The attribute [{$key}] must start with a letter and contain only letters, numbers, and underscores."]);
            }

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['user_metadata_attributes' => "The attribute [{$key}] is defined more than once."]);
            }

            $seen[$key] = true;

            $type = $attribute['type'] ?? 'string';
            $visibility = $attribute['visibility'] ?? 'public';

            if (! is_string($type) || ! array_key_exists($type, TenantApplicationEnvironment::METADATA_ATTRIBUTE_TYPES)) {
                throw ValidationException::withMessages(['user_metadata_attributes' => "Choose a valid type for the attribute [{$key}]."]);
            }

            if (! is_string($visibility) || ! array_key_exists($visibility, TenantApplicationEnvironment::METADATA_ATTRIBUTE_VISIBILITIES)) {
                throw ValidationException::withMessages(['user_metadata_attributes' => "Choose a valid visibility for the attribute [{$key}]."]);
            }

            $normalized[] = ['key' => $key, 'type' => $type, 'visibility' => $visibility];
        }

        return $normalized;
    }

    /**
     * Enabled capabilities for the environment being edited, shown as a summary.
     *
     * @return list<array{label: string, enabled: bool}>
     */
    public function capabilitySummary(): array
    {
        $record = $this->getRecord();
        $environment = $record->environments()->where('environment', $this->data['environment'] ?? $record->environment)->first();

        if (! $environment instanceof TenantApplicationEnvironment) {
            return [];
        }

        return [
            ['label' => 'Email sign-up', 'enabled' => $environment->allowsEmailSignup()],
            ['label' => 'Email sign-in', 'enabled' => $environment->allowsEmailSignin()],
            ['label' => 'Password', 'enabled' => $environment->allowsPasswordAuthentication()],
            ['label' => 'Email verification', 'enabled' => $environment->requiresEmailVerification()],
            ['label' => 'Passkeys', 'enabled' => $environment->allowsPasskeys()],
            ['label' => 'Biometric', 'enabled' => $environment->allowsPlatformAuthenticators()],
            ['label' => 'Metadata attributes', 'enabled' => $environment->userMetadataAttributes() !== []],
        ];
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
