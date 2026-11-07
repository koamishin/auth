<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\MultiSession;
use Illuminate\Auth\Events\Logout;

/**
 * Drops a signed-out account from the browser's account registry.
 *
 * Only the browser's main session counts: secondary guards being logged out
 * during an account switch must not remove the account being left behind,
 * because keeping it switchable is the whole point of switching.
 */
class ForgetMultiSessionAccount
{
    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        if ((string) $event->guard !== (string) config('fortify.guard', 'web')) {
            return;
        }

        app(MultiSession::class)->forget($event->user);
    }
}
