<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How users gain access to an application.
 */
enum AccessMode: string
{
    case Open = 'open';
    case InviteOnly = 'invite_only';
    case Waitlist = 'waitlist';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InviteOnly => 'Invite-only',
            self::Waitlist => 'Waitlist',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Open => 'Anyone can sign up.',
            self::InviteOnly => 'Only people an administrator adds can sign in.',
            self::Waitlist => 'Sign-ups are closed; interested users join a waitlist.',
        };
    }

    /**
     * Whether someone may create their own account without an invitation.
     */
    public function allowsPublicSignup(): bool
    {
        return $this === self::Open;
    }

    /**
     * The mode implied by the older boolean setting, so existing applications
     * keep behaving exactly as they did.
     */
    public static function fromLegacySignups(bool $allowSignups): self
    {
        return $allowSignups ? self::Open : self::InviteOnly;
    }
}
