<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Binds an email verification link to the device and browser that requested it,
 * so a link opened somewhere else cannot be used to verify the address.
 *
 * A random token is stored in a long lived cookie and only its hash travels in
 * the signed link, so the link itself reveals nothing usable.
 */
final class VerificationDevice
{
    public const COOKIE = 'koamishin_verification_device';

    /** How long the device cookie is remembered, in minutes. */
    private const LIFETIME_MINUTES = 60 * 24 * 30;

    /**
     * The token for this device, optionally creating and persisting one so the
     * link being generated now can be tied to it.
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

        // Make the new token readable for the rest of this request, because the
        // signed link is built before the response is sent.
        $request->cookies->set(self::COOKIE, $token);

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $token,
            minutes: self::LIFETIME_MINUTES,
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $token;
    }

    /**
     * The value embedded in the signed link for this device.
     */
    public function fingerprint(Request $request, bool $create = false): ?string
    {
        $token = $this->token($request, $create);

        return $token === null ? null : $this->hash($token);
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Whether a link carrying $fingerprint was requested from this device.
     */
    public function matches(Request $request, ?string $fingerprint): bool
    {
        if (! is_string($fingerprint) || $fingerprint === '') {
            return true;
        }

        $token = $this->token($request);

        return is_string($token) && hash_equals($fingerprint, $this->hash($token));
    }
}
