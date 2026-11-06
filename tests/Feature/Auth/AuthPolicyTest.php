<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Concerns\ProfileValidationRules;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationProvisioner;
use App\Support\PasskeyPolicy;
use App\Support\PasswordPolicy;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;

/**
 * @return array{0: TenantApplication, 1: TenantApplicationEnvironment}
 */
function authPolicyEnvironment(string $slug, array $settings = []): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => $slug.' app',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://'.$slug.'.internal/callback'],
    ]);

    $environment = $application->environments()->where('environment', 'development')->sole();

    if ($settings !== []) {
        $environment->update(['settings' => [...$environment->settings, ...$settings]]);
    }

    return [$application, $environment->refresh()];
}

function bindEnvironment(?TenantApplicationEnvironment $environment): void
{
    request()->attributes->set('koamishin.application_environment', $environment);
}

test('the configured minimum password length is actually enforced', function (): void {
    [, $environment] = authPolicyEnvironment('password-minimum', ['auth_password_min_length' => 12]);

    bindEnvironment($environment);

    expect(PasswordPolicy::minimum($environment))->toBe(12);

    $rule = PasswordPolicy::forCurrentRequest();
    $fails = false;

    try {
        validator(
            ['password' => 'short1'],
            ['password' => ['required', 'string', $rule]],
        )->validate();
        $fails = true;
    } catch (ValidationException) {
        $fails = false;
    }

    // A password below the configured minimum is rejected...
    expect($fails)->toBeFalse();

    // ...and one at or above it passes.
    validator(
        ['password' => 'a-long-enough-password'],
        ['password' => ['required', 'string', $rule]],
    )->validate();

    expect($rule)->toBeInstanceOf(Password::class);
});

test('the platform minimum is never lowered by an application setting', function (): void {
    [, $environment] = authPolicyEnvironment('password-floor', ['auth_password_min_length' => 8]);

    bindEnvironment($environment);

    // Outside production the fallback floor is 8, so an application asking for 8
    // must not push it lower, and a higher value must win.
    expect(PasswordPolicy::minimum($environment))->toBe(8);
    expect(PasswordPolicy::minimum(null))->toBe(PasswordPolicy::FALLBACK_MINIMUM);

    $environment->update(['settings' => [...$environment->settings, 'auth_password_min_length' => 20]]);
    expect(PasswordPolicy::minimum($environment->refresh()))->toBe(20);
});

test('registration enforces the application password minimum', function (): void {
    [, $environment] = authPolicyEnvironment('password-registration', ['auth_password_min_length' => 16]);
    bindEnvironment($environment);

    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Too Short',
        'email' => 'short@password-registration.test',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
    ]))->toThrow(ValidationException::class);

    $user = app(CreateNewUser::class)->create([
        'name' => 'Long Enough',
        'email' => 'long@password-registration.test',
        'password' => 'Password-1234-5678!',
        'password_confirmation' => 'Password-1234-5678!',
    ]);

    expect($user->exists)->toBeTrue();
});

test('disabling passwords stops new passwords but keeps existing ones working', function (): void {
    [, $environment] = authPolicyEnvironment('password-disabled', ['auth_password_enabled' => false]);
    bindEnvironment($environment);

    // New passwords cannot be created, whether by sign-up or reset.
    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Blocked Signup',
        'email' => 'blocked@password-disabled.test',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
    ]))->toThrow(ValidationException::class);

    expect(User::query()->where('email', 'blocked@password-disabled.test')->exists())->toBeFalse();

    expect(fn () => app(ResetUserPassword::class)->reset(
        User::factory()->create(),
        ['password' => 'Password-1234!', 'password_confirmation' => 'Password-1234!'],
    ))->toThrow(ValidationException::class);

    // An existing password still authenticates: the setting gates creation, not sign-in.
    $existing = User::factory()->create([
        'email' => 'existing@password-disabled.test',
        'password' => Hash::make('existing-password'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    expect(Hash::check('existing-password', $existing->password))->toBeTrue();
    expect($environment->allowsPasswordAuthentication())->toBeFalse();
});

test('sign-in with an existing password is not blocked when password authentication is disabled', function (): void {
    [$application, $environment] = authPolicyEnvironment('password-signin', ['auth_password_enabled' => false]);

    $existing = User::factory()->create([
        'email' => 'member@password-signin.test',
        'password' => Hash::make('existing-password'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);
    $application->users('development')->attach($existing, ['role' => 'member', 'environment' => 'development']);

    // The password still validates against the stored hash through the login path.
    bindEnvironment($environment);

    $resolved = Auth::getProvider()->retrieveByCredentials([
        'email' => 'member@password-signin.test',
    ]);

    expect($resolved)->not->toBeNull();
    expect(Auth::getProvider()->validateCredentials($resolved, [
        'password' => 'existing-password',
    ]))->toBeTrue();
});

test('an account cannot exceed ten passkeys', function (): void {
    [, $environment] = authPolicyEnvironment('passkey-limit');
    bindEnvironment($environment);

    $user = User::factory()->create([
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    foreach (range(1, PasskeyPolicy::MAX_PER_ACCOUNT) as $index) {
        $user->passkeys()->create([
            'name' => 'Device '.$index,
            'credential_id' => 'credential-'.$index,
            'credential' => ['aaguid' => 'test'],
        ]);
    }

    expect($user->passkeys()->count())->toBe(PasskeyPolicy::MAX_PER_ACCOUNT);

    // Requesting registration options is refused once the limit is reached, so the
    // browser never even starts the ceremony.
    expect(fn () => app(GenerateRegistrationOptions::class)($user))
        ->toThrow(InvalidPasskeyException::class);

    // The storage path is guarded too, so posting a credential directly cannot
    // bypass the limit.
    expect(app(StorePasskey::class))->toBeInstanceOf(App\Services\Passkeys\StorePasskey::class);
    expect(fn () => PasskeyPolicy::assertCanRegister($user))->toThrow(InvalidPasskeyException::class);

    // Removing one makes room again, on both paths.
    $user->passkeys()->first()->delete();

    expect(fn () => app(GenerateRegistrationOptions::class)($user))->not->toThrow(InvalidPasskeyException::class);
    expect(fn () => PasskeyPolicy::assertCanRegister($user->refresh()))->not->toThrow(InvalidPasskeyException::class);
});

test('usernames must be 4 to 64 latin characters with no reserved symbols', function (string $username, bool $valid): void {
    [, $environment] = authPolicyEnvironment('username-rules');
    bindEnvironment($environment);

    $rules = (new class
    {
        use ProfileValidationRules;

        /** @return array<int, Rule|array<mixed>|string> */
        public function rules(): array
        {
            return $this->usernameRules();
        }
    })->rules();

    $errors = validator(['username' => $username], ['username' => $rules])->errors();

    expect($errors->has('username'))->toBe(! $valid, "username [{$username}]");
})->with([
    'valid simple' => ['adalove', true],
    'valid with digits' => ['ada1991', true],
    'valid with underscore' => ['ada_lovelace', true],
    'valid with hyphen' => ['ada-lovelace', true],
    'valid at max length' => [str_repeat('a', 64), true],
    'too short' => ['ada', false],
    'too short boundary' => ['abc', false],
    'too long' => [str_repeat('a', 65), false],
    'reserved hash' => ['ada#1', false],
    'reserved plus' => ['ada+1', false],
    'reserved tilde' => ['ada~', false],
    'reserved dot' => ['ada.lovelace', false],
    'reserved exclamation' => ['ada!', false],
    'reserved dollar' => ['ada$', false],
    'reserved caret' => ['ada^x', false],
    'non latin' => ['мария', false],
    'spaces' => ['ada lovelace', false],
]);
