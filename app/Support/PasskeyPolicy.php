<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;

/**
 * Limits how many passkeys a single account may register.
 */
final class PasskeyPolicy
{
    /**
     * Maximum passkeys per account, matching the WebAuthn guidance Clerk
     * documents of ten credentials.
     */
    public const MAX_PER_ACCOUNT = 10;

    /**
     * @throws InvalidPasskeyException when the account already holds the maximum.
     */
    public static function assertCanRegister(User $user): void
    {
        if ($user->passkeys()->count() >= self::MAX_PER_ACCOUNT) {
            throw InvalidPasskeyException::make(
                'This account already has the maximum of '.self::MAX_PER_ACCOUNT.' passkeys. Remove one before adding another.'
            );
        }
    }
}
