<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\EmailCodePurpose;
use App\Models\EmailVerificationCode;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Issues and checks the one-time codes used to verify an email address or to
 * sign in without a password.
 *
 * Codes are stored hashed, expire quickly, can only be used once, and are
 * limited in how often they can be requested and how many guesses they allow.
 */
class EmailCodeService
{
    /** Number of digits in a generated code. */
    public const CODE_LENGTH = 6;

    /** How long a code stays valid, in minutes. */
    public const TTL_MINUTES = 10;

    /** How long a user must wait before another code can be requested. */
    public const RESEND_COOLDOWN_SECONDS = 30;

    /** How many incorrect guesses a single code tolerates. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Send a fresh code, replacing any code that is still live.
     *
     * Returns null without sending when the address is refused by the
     * application, so no caller can issue a code to a blocked address.
     *
     * @throws ValidationException when the resend cooldown is still running.
     */
    public function send(User $user, EmailCodePurpose $purpose): ?EmailVerificationCode
    {
        $environment = $user->tenantApplicationEnvironment;

        if ($environment instanceof TenantApplicationEnvironment
            && ! app(ApplicationAccessPolicy::class)->allows($environment, $user->email)) {
            return null;
        }

        $this->assertCooldownElapsed($user, $purpose);

        $code = $this->generateCode();

        $record = EmailVerificationCode::query()->create([
            'user_id' => $user->getKey(),
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'ip_address' => request()->ip(),
        ]);

        // Only the newest code should work, so retire everything older.
        EmailVerificationCode::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose)
            ->whereKeyNot($record->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $user->notify(new EmailVerificationCodeNotification($code, $purpose, self::TTL_MINUTES));

        return $record;
    }

    /**
     * Check a submitted code and consume it when correct.
     *
     * @throws ValidationException when the code is missing, expired, wrong, or exhausted.
     */
    public function verify(User $user, string $code, EmailCodePurpose $purpose): void
    {
        $record = $this->liveCode($user, $purpose);

        if (! $record instanceof EmailVerificationCode) {
            throw ValidationException::withMessages([
                'code' => __('Request a new code to continue.'),
            ]);
        }

        if ($record->hasExpired()) {
            $record->update(['consumed_at' => now()]);

            throw ValidationException::withMessages([
                'code' => __('This code has expired. Request a new one.'),
            ]);
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            $record->update(['consumed_at' => now()]);

            throw ValidationException::withMessages([
                'code' => __('Too many incorrect attempts. Request a new code.'),
            ]);
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            // Burn the code as soon as the final allowed attempt is used up.
            if ($record->attempts >= self::MAX_ATTEMPTS) {
                $record->update(['consumed_at' => now()]);

                throw ValidationException::withMessages([
                    'code' => __('Too many incorrect attempts. Request a new code.'),
                ]);
            }

            throw ValidationException::withMessages([
                'code' => __('That code is not correct.'),
            ]);
        }

        $record->update(['consumed_at' => now()]);
    }

    /**
     * Seconds remaining before another code may be requested, or zero.
     */
    public function cooldownRemaining(User $user, EmailCodePurpose $purpose): int
    {
        $latest = EmailVerificationCode::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();

        if (! $latest instanceof EmailVerificationCode) {
            return 0;
        }

        $availableAt = $latest->created_at?->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        if ($availableAt === null || $availableAt->isPast()) {
            return 0;
        }

        return (int) now()->diffInSeconds($availableAt);
    }

    /**
     * @throws ValidationException
     */
    private function assertCooldownElapsed(User $user, EmailCodePurpose $purpose): void
    {
        $remaining = $this->cooldownRemaining($user, $purpose);

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'code' => __('Please wait :seconds seconds before requesting another code.', ['seconds' => $remaining]),
            ]);
        }
    }

    private function liveCode(User $user, EmailCodePurpose $purpose): ?EmailVerificationCode
    {
        /** @var EmailVerificationCode|null $record */
        $record = EmailVerificationCode::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose)
            ->live()
            ->latest('id')
            ->first();

        return $record;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, (10 ** self::CODE_LENGTH) - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}
