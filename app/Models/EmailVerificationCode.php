<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailCodePurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use, short-lived code sent to a user's email address.
 *
 * Only the hash of the code is stored, so a database leak does not reveal
 * usable codes.
 *
 * @property int $id
 * @property int $user_id
 * @property EmailCodePurpose $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class EmailVerificationCode extends Model
{
    protected $fillable = [
        'user_id',
        'purpose',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'purpose' => EmailCodePurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The newest code that has not been used or invalidated yet.
     *
     * @param  Builder<EmailVerificationCode>  $query
     * @return Builder<EmailVerificationCode>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at');
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
