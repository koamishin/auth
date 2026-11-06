<?php

declare(strict_types=1);

namespace App\Services\Passkeys;

use App\Models\TenantApplicationEnvironment;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions as BaseGenerateRegistrationOptions;
use Webauthn\AuthenticatorSelectionCriteria;

/**
 * Applies an application environment's biometric preferences to passkey
 * registration, so built-in authenticators (Touch ID, Face ID, Windows Hello)
 * and the user-verification requirement are configurable per application.
 */
class GenerateRegistrationOptions extends BaseGenerateRegistrationOptions
{
    public function authenticatorSelection(): AuthenticatorSelectionCriteria
    {
        $environment = app(PasskeyEnvironment::class)->current();

        if (! $environment instanceof TenantApplicationEnvironment) {
            return parent::authenticatorSelection();
        }

        return AuthenticatorSelectionCriteria::create(
            authenticatorAttachment: $environment->allowsPlatformAuthenticators()
                ? AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE
                : AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_CROSS_PLATFORM,
            userVerification: $environment->requiresPasskeyUserVerification()
                ? AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED
                : AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
        );
    }
}
