<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A task the session still owes before it is complete.
 *
 * A session token with pending tasks reports status `pending`, the way Clerk
 * reports sessions that have not finished their verification steps.
 */
enum SessionTask: string
{
    case EmailVerification = 'email_verification';
    case MultiFactor = 'mfa';
}
