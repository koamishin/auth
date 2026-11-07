<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Remembers when each class of factor was last verified in this session.
 *
 * Session tokens report the ages as their `fva` claim: minutes since the last
 * first-factor verification (password, passkey, emailed code, or social
 * sign-in) and minutes since the last second-factor verification, or -1 when
 * a class was never verified in this session.
 *
 * The stamps belong to one session and one account. Switching accounts drops
 * them with the rest of the session state, so a factor verified for one
 * account never vouches for another.
 */
class FactorVerifications
{
    /** Session key holding the verification timestamps. */
    public const SESSION_KEY = 'koamishin.factor_verifications';

    /**
     * Request attribute silencing recording, set while an account switch
     * re-establishes a session without verifying any factor.
     */
    public const SILENT_ATTRIBUTE = 'koamishin.factor_verification_silent';

    public static function recordFirstFactor(Request $request): void
    {
        self::record($request, 'first');
    }

    public static function recordSecondFactor(Request $request): void
    {
        self::record($request, 'second');
    }

    /**
     * The ages of the first and second factor verifications, in minutes.
     *
     * -1 means the factor has never been verified in this session.
     *
     * @return array{0: int, 1: int}
     */
    public static function ages(Request $request): array
    {
        $stamps = $request->session()->get(self::SESSION_KEY, []);

        return [
            self::age($stamps['first'] ?? null),
            self::age($stamps['second'] ?? null),
        ];
    }

    private static function record(Request $request, string $kind): void
    {
        if ($request->attributes->getBoolean(self::SILENT_ATTRIBUTE)) {
            return;
        }

        $stamps = $request->session()->get(self::SESSION_KEY, []);
        $stamps[$kind] = now()->timestamp;

        $request->session()->put(self::SESSION_KEY, $stamps);
    }

    private static function age(mixed $verifiedAt): int
    {
        if (! is_int($verifiedAt)) {
            return -1;
        }

        return max(0, intdiv(now()->timestamp - $verifiedAt, 60));
    }
}
