<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\MultiSession;
use Illuminate\Auth\Events\Login;

/**
 * Registers every completed sign-in on the browser's account registry.
 *
 * Listening on the shared Login event is what keeps this correct for every
 * sign-in method — password, passkey, emailed code, social, and the setup
 * flow — instead of being wired to one path and missing the rest.
 */
class RegisterMultiSessionAccount
{
    /** Guards that represent the browser's own sessions. */
    private const SESSION_GUARDS = ['web', 'portal'];

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        if (! in_array((string) $event->guard, self::SESSION_GUARDS, true)) {
            return;
        }

        // An impersonated user did not sign in on this browser, and must never
        // become switchable from the impersonator's registry.
        if (app('impersonate')->isImpersonating()) {
            return;
        }

        app(MultiSession::class)->attach($event->user);
    }
}
