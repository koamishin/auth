<?php

declare(strict_types=1);

namespace App\Services\Passkeys;

use App\Models\TenantApplicationEnvironment;
use Laravel\Passkeys\Actions\GenerateVerificationOptions as BaseGenerateVerificationOptions;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Applies an application environment's biometric preferences to passkey
 * verification (sign-in), mirroring the registration criteria.
 */
class GenerateVerificationOptions extends BaseGenerateVerificationOptions
{
    public function __invoke(?PasskeyUser $user = null): PublicKeyCredentialRequestOptions
    {
        $environment = app(PasskeyEnvironment::class)->current();

        if (! $environment instanceof TenantApplicationEnvironment) {
            return parent::__invoke($user);
        }

        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: Passkeys::relyingPartyId(),
            allowCredentials: $this->allowCredentials($user),
            userVerification: $environment->requiresPasskeyUserVerification()
                ? AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED
                : AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: Passkeys::timeout(),
        );
    }
}
