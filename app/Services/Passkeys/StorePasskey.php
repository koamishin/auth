<?php

declare(strict_types=1);

namespace App\Services\Passkeys;

use App\Models\User;
use App\Support\PasskeyPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passkeys\Actions\StorePasskey as BaseStorePasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Enforces the per-account passkey limit on the storage path as well, so the
 * limit cannot be bypassed by posting credentials directly.
 */
class StorePasskey extends BaseStorePasskey
{
    /**
     * @throws InvalidPasskeyException when the account is at its passkey limit.
     */
    public function __invoke(
        Authenticatable $user,
        string $name,
        PublicKeyCredential $credential,
        PublicKeyCredentialCreationOptions $options
    ): Passkey {
        if ($user instanceof User) {
            PasskeyPolicy::assertCanRegister($user);
        }

        return parent::__invoke($user, $name, $credential, $options);
    }
}
