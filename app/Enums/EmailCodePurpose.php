<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a one-time email code is being used for.
 */
enum EmailCodePurpose: string
{
    case Verification = 'verification';
    case SignIn = 'sign_in';

    public function label(): string
    {
        return match ($this) {
            self::Verification => 'Verify your email address',
            self::SignIn => 'Sign in to your account',
        };
    }
}
