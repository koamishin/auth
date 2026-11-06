<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A second factor a user can enrol to satisfy multi-factor authentication.
 */
enum SecondFactor: string
{
    case AuthenticatorApp = 'authenticator_app';
    case EmailCode = 'email_code';

    public function label(): string
    {
        return match ($this) {
            self::AuthenticatorApp => 'Authenticator app',
            self::EmailCode => 'Email code',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AuthenticatorApp => 'Time-based one-time codes from an authenticator app.',
            self::EmailCode => 'One-time codes sent to the user\'s email address.',
        };
    }
}
