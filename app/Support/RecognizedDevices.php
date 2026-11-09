<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\RecognizedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Knows which browsers an account has signed in from before.
 *
 * A browser carries a long-lived device token cookie; the table stores one
 * row per (account, device token) with the hash of that token. The first
 * sign-in on a device is what earns an account owner a "new sign-in" notice —
 * the check is per account, so a device familiar to one account is still new
 * to another.
 */
class RecognizedDevices
{
    /** The browser's device token cookie. */
    public const COOKIE = 'koamishin_device';

    /** How long the device token lives, in minutes (one year). */
    public const LIFETIME_MINUTES = 525600;

    /**
     * The device token for this browser, optionally minting one.
     */
    public function token(Request $request, bool $create = false): ?string
    {
        $token = $request->cookies->get(self::COOKIE);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        if (! $create) {
            return null;
        }

        $token = Str::random(64);
        $request->cookies->set(self::COOKIE, $token);

        cookie()->queue(cookie(
            self::COOKIE,
            $token,
            self::LIFETIME_MINUTES,
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $token;
    }

    /**
     * Remember this device for the account and report whether it is new to it.
     *
     * The first sign-in from a browser returns true; later sign-ins from the
     * same browser return false.
     */
    public function record(Request $request, User $user): bool
    {
        $token = $this->token($request, create: true);

        if (! is_string($token)) {
            return false;
        }

        $known = RecognizedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('token_hash', $this->hash($token))
            ->first();

        if ($known instanceof RecognizedDevice) {
            $known->forceFill([
                'user_agent' => (string) $request->userAgent(),
                'ip_address' => $request->ip(),
                'last_seen_at' => now(),
            ])->save();

            return false;
        }

        RecognizedDevice::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $this->hash($token),
            'user_agent' => (string) $request->userAgent(),
            'ip_address' => $request->ip(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return true;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
