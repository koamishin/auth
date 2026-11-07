<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Support\FactorVerifications;
use Illuminate\Auth\Events\Login;

/**
 * Records that a first factor was verified whenever a sign-in completes.
 *
 * Password, passkey, emailed code, social, invitation, and setup sign-ins all
 * land here, because they all end at the shared Login event — recording at the
 * event instead of at each method is what keeps every method covered.
 * Impersonation logs in quietly and never records anything, and an account
 * switch marks itself silent because it proves no factor.
 */
class RecordFactorVerification
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        FactorVerifications::recordFirstFactor(request());
    }
}
