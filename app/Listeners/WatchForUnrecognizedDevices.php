<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Session;
use App\Models\User;
use App\Notifications\NewDeviceSignInNotification;
use App\Support\ApplicationContext;
use App\Support\RecognizedDevices;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\URL;

/**
 * Warns the account owner when a sign-in comes from a device the account has
 * not used before.
 *
 * Listening on the shared Login event is what keeps every sign-in method —
 * password, passkey, emailed code, social, invitation — watched alike.
 * Impersonation logs in quietly and never counts: no one signed in.
 */
class WatchForUnrecognizedDevices
{
    /** Request attributes guarding the alert against double dispatch. */
    private const QUEUED_ATTRIBUTE = 'koamishin.signin_alert_queued';

    private const SENT_ATTRIBUTE = 'koamishin.signin_alert_sent';

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        if (app('impersonate')->isImpersonating()) {
            return;
        }

        $request = request();

        if ($request->attributes->getBoolean(self::QUEUED_ATTRIBUTE)) {
            return;
        }

        // Recognition is a fact about the device whatever the alert setting
        // says, so turning alerts on later does not re-report old devices.
        $isNewDevice = app(RecognizedDevices::class)->record($request, $event->user);

        $environment = ApplicationContext::environment($request);

        if (! ($environment?->alertsOnUnrecognizedDeviceSignin() ?? true)) {
            return;
        }

        if (! $isNewDevice) {
            return;
        }

        $request->attributes->set(self::QUEUED_ATTRIBUTE, true);

        $user = $event->user;
        $deviceName = Session::deviceName($request->userAgent());
        $ipAddress = $request->ip();
        $supportEmail = $environment?->supportEmail();

        // Sign-in regenerates the session after this event, so the alert waits
        // until the request is done before naming the session it belongs to.
        app()->terminating(function () use ($request, $user, $deviceName, $ipAddress, $supportEmail): void {
            if ($request->attributes->getBoolean(self::SENT_ATTRIBUTE)) {
                return;
            }

            $request->attributes->set(self::SENT_ATTRIBUTE, true);

            $user->notify(new NewDeviceSignInNotification(
                deviceName: $deviceName,
                ipAddress: $ipAddress,
                signedInAt: now()->toDayDateTimeString(),
                revocationUrl: URL::temporarySignedRoute(
                    'signin-alert.revoke',
                    now()->addDay(),
                    ['user' => $user->getKey(), 'session' => (string) $request->session()->getId()],
                ),
                supportEmail: $supportEmail,
            ));
        });
    }
}
