<?php

declare(strict_types=1);

namespace App\Filament\Resources\TenantApplications\Pages;

use App\Enums\AccessMode;
use App\Enums\FieldMode;
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
use Filament\Schemas\Components\Text;
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
                        Tabs\Tab::make('Access')
                            ->icon('heroicon-o-lock-closed')
                            ->schema($this->accessTab()),
                        Tabs\Tab::make('Multi-factor')
                            ->icon('heroicon-o-shield-exclamation')
                            ->schema($this->multiFactorTab()),
                        Tabs\Tab::make('Sessions')
                            ->icon('heroicon-o-clock')
                            ->schema($this->sessionsTab()),
                        Tabs\Tab::make('User model')
                            ->icon('heroicon-o-circle-stack')
                            ->schema($this->userModelTab()),
                        Tabs\Tab::make('Enterprise SSO')
                            ->icon('heroicon-o-building-office-2')
                            ->schema($this->comingSoonTab(
                                'Enterprise SSO',
                                'Signing in with a corporate identity provider (SAML or OIDC) per email domain is not built yet.',
                                'The organization-wide single sign-on setting on the Settings page shares a session between this organization\'s own applications. It is not an enterprise connection to an external identity provider.',
                            )),
                        Tabs\Tab::make('Web3')
                            ->icon('heroicon-o-wallet')
                            ->schema($this->comingSoonTab(
                                'Web3',
                                'Signing in with a crypto wallet is not built yet. It needs message signing and signature verification against the stored address.',
                                'A wallet address can be set on a user by an administrator today, but it cannot be used to authenticate.',
                            )),
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
                        ->helperText('Codes are single-use, expire after 10 minutes, and can only be requested again after 30 seconds.')
                        ->default('link')
                        ->inline()
                        ->live()
                        ->visible(fn (Get $get): bool => $get('email_verification_policy') !== 'disabled'),

                    TextInput::make('email_verification_link_ttl')
                        ->label('Link lifetime (minutes)')
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(1440)
                        ->default(TenantApplicationEnvironment::DEFAULT_VERIFICATION_LINK_TTL)
                        ->helperText('How long a verification link stays valid.')
                        // Kept in form state while hidden so switching method or policy
                        // back does not silently reset the configured value.
                        ->dehydratedWhenHidden()
                        ->visible(fn (Get $get): bool => $get('email_verification_policy') !== 'disabled' && $get('email_verification_method') === 'link'),

                    Toggle::make('email_verification_bind_device')
                        ->label('Require the same device and browser')
                        ->helperText('The link only works on the device and browser that requested it, so an intercepted link cannot verify the address elsewhere.')
                        ->default(true)
                        ->rules(['boolean'])
                        ->dehydratedWhenHidden()
                        ->visible(fn (Get $get): bool => $get('email_verification_policy') !== 'disabled' && $get('email_verification_method') === 'link'),
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

                    Toggle::make('auth_email_code_signin')
                        ->label('Allow sign-in with an email code')
                        ->helperText('Users can request a one-time code instead of typing a password. The sign-in screen offers this as an alternative.')
                        ->default(false)
                        ->rules(['boolean']),
                ]),
        ];
    }

    /**
     * A tab for a capability that is on the roadmap but not implemented, so the
     * page describes the plan instead of offering controls that do nothing.
     *
     * @return array<int, Section>
     */
    private function comingSoonTab(string $title, string ...$notes): array
    {
        return [
            Section::make($title)
                ->description('Coming soon. Nothing on this tab is enforced yet.')
                ->icon('heroicon-o-clock')
                ->contained(false)
                ->components(array_map(
                    Text::make(...),
                    $notes,
                )),
        ];
    }

    /** @return array<int, Section> */
    private function phoneTab(): array
    {
        return [
            Section::make('Phone number')
                ->description('Collect a phone number from users as part of their profile.')
                ->icon('heroicon-o-device-phone-mobile')
                ->contained(false)
                ->components([
                    Select::make('phone_mode')
                        ->label('Phone number')
                        ->options(FieldMode::options())
                        ->default(FieldMode::Disabled->value)
                        ->required()
                        ->rules(['in:disabled,optional,required'])
                        ->helperText('Numbers are stored in international format, like +14155550123. SMS verification codes are coming soon, so numbers are collected but cannot yet be verified or used to sign in.'),
                ]),
        ];
    }

    /** @return array<int, Section> */
    private function usernameTab(): array
    {
        return [
            Section::make('Username')
                ->description('Let users carry a username on this application: collected at sign-up, editable in their profile, and accepted on the sign-in form instead of an email address.')
                ->icon('heroicon-o-user-circle')
                ->contained(false)
                ->components([
                    Select::make('username_mode')
                        ->label('Username')
                        ->options(FieldMode::options())
                        ->default(FieldMode::Disabled->value)
                        ->required()
                        ->rules(['in:disabled,optional,required'])
                        ->helperText('A username is 4–64 letters, numbers, hyphens, and underscores, and is unique per application environment. Optional lets users choose one; required makes every new user pick one. Turning this off also stops usernames being accepted on the sign-in form.'),
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
    private function accessTab(): array
    {
        return [
            Section::make('Access mode')
                ->description('Decide how people gain access to this application.')
                ->contained(false)
                ->components([
                    ToggleButtons::make('access_mode')
                        ->label('Who can sign up')
                        ->options(collect(AccessMode::cases())->mapWithKeys(
                            fn (AccessMode $mode): array => [$mode->value => $mode->label()],
                        )->all())
                        ->default(AccessMode::InviteOnly->value)
                        ->required()
                        ->live(),

                    Text::make('Open: anyone can create their own account.')
                        ->visible(fn (Get $get): bool => $get('access_mode') === AccessMode::Open->value),

                    Text::make('Invite-only: existing members keep signing in, and new accounts must be added by an administrator.')
                        ->visible(fn (Get $get): bool => $get('access_mode') === AccessMode::InviteOnly->value),

                    Text::make('Waitlist: sign-ups are closed. A waitlist screen for interested users is not built yet.')
                        ->visible(fn (Get $get): bool => $get('access_mode') === AccessMode::Waitlist->value),
                ]),

            Section::make('Identifier restrictions')
                ->description('Refuse or restrict the email addresses that can be used with this application.')
                ->contained(false)
                ->components([
                    Toggle::make('block_email_subaddresses')
                        ->label('Block email subaddresses')
                        ->helperText('Refuse addresses such as name+tag@example.com, which are often used to create many accounts from one mailbox.')
                        ->default(false)
                        ->rules(['boolean']),

                    Toggle::make('block_disposable_emails')
                        ->label('Block disposable email addresses')
                        ->helperText('Refuse throwaway providers such as mailinator.com and yopmail.com.')
                        ->default(false)
                        ->rules(['boolean']),
                ]),

            Section::make('Allowlist and blocklist')
                ->description('Block rules always win. If any allow rule exists, only matching addresses may be used.')
                ->contained(false)
                ->components([
                    Repeater::make('access_rules')
                        ->hiddenLabel()
                        ->addActionLabel('Add rule')
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state): ?string => filled($state['value'] ?? null)
                            ? ucfirst((string) ($state['type'] ?? '')).' '.($state['value'] ?? '')
                            : null)
                        ->components([
                            Select::make('type')
                                ->label('Rule')
                                ->options(['allow' => 'Allow', 'block' => 'Block'])
                                ->default('block')
                                ->required(),

                            Select::make('kind')
                                ->label('Matches')
                                ->options(['email' => 'Exact email address', 'domain' => 'Email domain'])
                                ->default('domain')
                                ->required(),

                            TextInput::make('value')
                                ->label('Value')
                                ->placeholder('e.g. example.com or person@example.com')
                                ->required()
                                ->maxLength(255),
                        ])
                        ->columns(3),
                ]),
        ];
    }

    /** @return array<int, Section> */
    private function multiFactorTab(): array
    {
        return [
            Section::make('Two-step verification')
                ->description('Require every user of this application to enrol a second factor before they can finish signing in.')
                ->contained(false)
                ->components([
                    Toggle::make('require_mfa')
                        ->label('Require two-step verification')
                        ->helperText('Users without a second factor are sent to a setup screen and cannot reach the application until they enrol one.')
                        ->default(false)
                        ->live()
                        ->rules(['boolean']),

                    Toggle::make('passkeys_satisfy_mfa')
                        ->label('Passkeys satisfy two-step verification')
                        ->helperText('A passkey already combines a device with a biometric or PIN, so signing in with one skips the second step. Turn this off to require a factor to be enrolled even for passkey users.')
                        ->default(true)
                        ->dehydratedWhenHidden()
                        ->rules(['boolean'])
                        ->visible(fn (Get $get): bool => $get('require_mfa') === true),
                ]),

            Section::make('Available factors')
                ->description('Users can enrol any of the following. Authenticator apps are available to every application; email codes require an email address.')
                ->contained(false)
                ->visible(fn (Get $get): bool => $get('require_mfa') === true)
                ->components([
                    Text::make('Authenticator app — time-based one-time codes from an authenticator app, with recovery codes.'),
                    Text::make('Email code — one-time codes sent to the user\'s email address.'),
                ]),
        ];
    }

    /** @return array<int, Section> */
    private function sessionsTab(): array
    {
        return [
            Section::make('Session lifetime')
                ->description('How long a signed in session lasts. At least one of these must be set, otherwise sessions would never expire.')
                ->icon('heroicon-o-clock')
                ->contained(false)
                ->components([
                    TextInput::make('session_inactivity_timeout')
                        ->label('Inactivity timeout (minutes)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(43200)
                        ->default(0)
                        ->helperText('Expire the session after this long without activity. Set to 0 to never expire from inactivity.'),

                    TextInput::make('session_max_lifetime')
                        ->label('Maximum lifetime (minutes)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(525600)
                        ->default(TenantApplicationEnvironment::DEFAULT_SESSION_MAX_LIFETIME)
                        ->helperText('Expire the session after this long regardless of activity. Set to 0 to rely on the inactivity timeout alone.'),

                    Text::make('Changes apply to new sessions only. Sessions that are already signed in keep the limits they started with, so lowering a limit does not sign anybody out. To end a session now, revoke it from the user\'s profile.'),
                ]),
        ];
    }

    /** @return array<int, Section> */
    private function userModelTab(): array
    {
        return [
            Section::make('User permissions')
                ->description('Control what users of this application are allowed to change about themselves.')
                ->contained(false)
                ->components([
                    Toggle::make('allow_name_parts')
                        ->label('Collect a first and last name')
                        ->helperText('Sign-up and profile forms ask for a first and last name. The display name is kept in step with them.')
                        ->default(false)
                        ->rules(['boolean']),

                    Toggle::make('allow_account_deletion')
                        ->label('Allow users to delete their accounts')
                        ->helperText('Turn off to keep accounts on the application permanently. Administrators can still delete a user.')
                        ->default(true)
                        ->rules(['boolean']),
                ]),

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
            'auth_email_code_signin' => false,
            'email_verification_bind_device' => true,
            'block_email_subaddresses' => false,
            'block_disposable_emails' => false,
            'require_mfa' => false,
            'passkeys_satisfy_mfa' => true,
            'allow_name_parts' => false,
            'allow_account_deletion' => true,
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

        $usernameMode = $data['username_mode'] ?? FieldMode::Disabled->value;
        if (! array_key_exists($usernameMode, FieldMode::options())) {
            throw ValidationException::withMessages(['username_mode' => 'Choose a valid username setting.']);
        }

        if ($usernameMode === FieldMode::Disabled->value) {
            unset($settings['username_mode']);
        } else {
            $settings['username_mode'] = $usernameMode;
        }

        unset($data['username_mode']);

        $phoneMode = $data['phone_mode'] ?? FieldMode::Disabled->value;
        if (! array_key_exists($phoneMode, FieldMode::options())) {
            throw ValidationException::withMessages(['phone_mode' => 'Choose a valid phone number setting.']);
        }

        if ($phoneMode === FieldMode::Disabled->value) {
            unset($settings['phone_mode']);
        } else {
            $settings['phone_mode'] = $phoneMode;
        }

        unset($data['phone_mode']);

        $linkTtl = (int) ($data['email_verification_link_ttl'] ?? TenantApplicationEnvironment::DEFAULT_VERIFICATION_LINK_TTL);
        if ($linkTtl < 5 || $linkTtl > 1440) {
            throw ValidationException::withMessages(['email_verification_link_ttl' => 'Choose a link lifetime between 5 and 1440 minutes.']);
        }

        if ($linkTtl === TenantApplicationEnvironment::DEFAULT_VERIFICATION_LINK_TTL) {
            unset($settings['email_verification_link_ttl']);
        } else {
            $settings['email_verification_link_ttl'] = $linkTtl;
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

        // Session lifetime. At least one limit has to remain, otherwise a signed
        // in session would never expire.
        $inactivity = (int) ($data['session_inactivity_timeout'] ?? 0);
        $maxLifetime = (int) ($data['session_max_lifetime'] ?? TenantApplicationEnvironment::DEFAULT_SESSION_MAX_LIFETIME);

        if ($inactivity < 0 || $inactivity > 43200) {
            throw ValidationException::withMessages(['session_inactivity_timeout' => 'Choose an inactivity timeout between 0 and 43200 minutes.']);
        }

        if ($maxLifetime < 0 || $maxLifetime > 525600) {
            throw ValidationException::withMessages(['session_max_lifetime' => 'Choose a maximum lifetime between 0 and 525600 minutes.']);
        }

        if ($inactivity === 0 && $maxLifetime === 0) {
            throw ValidationException::withMessages(['session_max_lifetime' => 'Keep either an inactivity timeout or a maximum lifetime, so sessions still expire.']);
        }

        if ($inactivity === 0) {
            unset($settings['session_inactivity_timeout']);
        } else {
            $settings['session_inactivity_timeout'] = $inactivity;
        }

        if ($maxLifetime === TenantApplicationEnvironment::DEFAULT_SESSION_MAX_LIFETIME) {
            unset($settings['session_max_lifetime']);
        } else {
            $settings['session_max_lifetime'] = $maxLifetime;
        }

        unset($data['session_inactivity_timeout'], $data['session_max_lifetime']);

        $accessMode = $data['access_mode'] ?? null;
        if (! is_string($accessMode) || ! AccessMode::tryFrom($accessMode) instanceof AccessMode) {
            throw ValidationException::withMessages(['access_mode' => 'Choose a valid access mode.']);
        }

        $settings['access_mode'] = $accessMode;

        // Keep the legacy flag in step so anything still reading it agrees with
        // the access mode the administrator chose.
        if (AccessMode::from($accessMode)->allowsPublicSignup()) {
            $settings['allow_signups'] = true;
        } else {
            unset($settings['allow_signups']);
        }

        unset($data['access_mode']);

        $accessRules = $this->normalizeAccessRules($data['access_rules'] ?? []);
        if ($accessRules === []) {
            unset($settings['access_rules']);
        } else {
            $settings['access_rules'] = $accessRules;
        }

        unset($data['access_rules']);

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
            $data['email_verification_link_ttl'],
            $data['auth_password_min_length'],
            $data['user_metadata_attributes'],
            $data['user_metadata_defaults'],
        );

        $configuration->update(['settings' => $settings]);

        return $record;
    }

    /**
     * Validate and normalise allow and block rules.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return list<array{type: string, kind: string, value: string}>
     */
    private function normalizeAccessRules(array $rules): array
    {
        $normalized = [];
        $seen = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $type = $rule['type'] ?? null;
            $kind = $rule['kind'] ?? null;
            $value = is_string($rule['value'] ?? null) ? trim($rule['value']) : '';

            if ($value === '') {
                continue;
            }

            if (! in_array($type, ['allow', 'block'], true)) {
                throw ValidationException::withMessages(['access_rules' => 'Each rule must allow or block.']);
            }

            if (! in_array($kind, ['email', 'domain'], true)) {
                throw ValidationException::withMessages(['access_rules' => 'Each rule must match an email address or a domain.']);
            }

            if ($kind === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['access_rules' => "[{$value}] is not a valid email address."]);
            }

            if ($kind === 'domain' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', ltrim($value, '@')) !== 1) {
                throw ValidationException::withMessages(['access_rules' => "[{$value}] is not a valid domain."]);
            }

            $normalizedValue = $kind === 'email'
                ? mb_strtolower($value)
                : mb_strtolower(ltrim($value, '@'));

            $key = $type.'|'.$kind.'|'.$normalizedValue;

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['access_rules' => "The rule for [{$value}] is defined more than once."]);
            }

            $seen[$key] = true;

            $normalized[] = ['type' => $type, 'kind' => $kind, 'value' => $normalizedValue];
        }

        return $normalized;
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
