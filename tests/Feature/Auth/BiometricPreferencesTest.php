<?php

use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Webauthn\AuthenticatorSelectionCriteria;

function biometricEnvironment(string $slug, array $settings): array
{
    $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);
    $application = $tenant->applications()->create([
        'name' => $slug,
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://'.$slug.'.internal/callback'],
    ]);

    $environment = $application->environments()->create([
        'environment' => 'development',
        'redirect_uris' => ['https://'.$slug.'.internal/callback'],
        'settings' => $settings,
    ]);

    $user = User::factory()->create([
        'email' => $slug.'@biometric.test',
        'password' => Hash::make('app-password'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    return [$environment, $user];
}

function withApplicationEnvironment(?TenantApplication $application, string $environment): void
{
    request()->attributes->set('koamishin.application_environment', null);

    if ($application instanceof TenantApplication) {
        request()->attributes->set(
            'koamishin.application_environment',
            $application->environments()->where('environment', $environment)->sole(),
        );
    }
}

test('biometric preferences drive the passkey registration criteria', function (): void {
    [$environment, $user] = biometricEnvironment('biometric-registration', []);

    // Defaults: built-in authenticators allowed, user verification required.
    withApplicationEnvironment($environment->application, 'development');
    $criteria = app(GenerateRegistrationOptions::class)->authenticatorSelection();
    expect($criteria->authenticatorAttachment)->toBe(AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE);
    expect($criteria->userVerification)->toBe(AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED);

    // Biometrics off: only external security keys may be used.
    $environment->update(['settings' => ['passkey_platform_authenticators' => false]]);
    withApplicationEnvironment($environment->application, 'development');
    $criteria = app(GenerateRegistrationOptions::class)->authenticatorSelection();
    expect($criteria->authenticatorAttachment)->toBe(AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_CROSS_PLATFORM);

    // Presence-only: user verification is no longer required.
    $environment->update(['settings' => ['passkey_require_user_verification' => false]]);
    withApplicationEnvironment($environment->application, 'development');
    $criteria = app(GenerateRegistrationOptions::class)->authenticatorSelection();
    expect($criteria->userVerification)->toBe(AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED);

    expect($user->exists)->toBeTrue();
});

test('biometric preferences drive the passkey verification criteria', function (): void {
    [$environment] = biometricEnvironment('biometric-verification', []);

    withApplicationEnvironment($environment->application, 'development');
    $options = app(GenerateVerificationOptions::class)();
    expect($options->userVerification)->toBe(AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED);

    $environment->update(['settings' => ['passkey_require_user_verification' => false]]);
    withApplicationEnvironment($environment->application, 'development');
    $options = app(GenerateVerificationOptions::class)();
    expect($options->userVerification)->toBe(AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED);
});

test('passkey ceremonies outside an application keep the portal defaults', function (): void {
    withApplicationEnvironment(null, 'development');

    $criteria = app(GenerateRegistrationOptions::class)->authenticatorSelection();
    expect($criteria->authenticatorAttachment)->toBe(AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE);
    expect($criteria->userVerification)->toBe(AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED);

    $options = app(GenerateVerificationOptions::class)();
    expect($options->userVerification)->toBe(AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED);
});
