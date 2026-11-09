<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An invitation for one email address to join an application environment.
 *
 * Only the hash of the token is stored, so the database never holds anything
 * that could be used to accept an invitation.
 *
 * @property int $id
 * @property int $tenant_application_environment_id
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $inviter
 */
class ApplicationInvitation extends Model
{
    /** How long an invitation stays valid, in days. */
    public const VALID_FOR_DAYS = 14;

    protected $fillable = [
        'tenant_application_environment_id',
        'email',
        'role',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
        'accepted_by',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TenantApplicationEnvironment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(TenantApplicationEnvironment::class, 'tenant_application_environment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<User, $this> */
    public function accepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * @param  Builder<ApplicationInvitation>  $query
     * @return Builder<ApplicationInvitation>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    public function status(): InvitationStatus
    {
        return match (true) {
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->hasExpired() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Whether this invitation can still be used.
     */
    public function isUsable(): bool
    {
        return $this->status() === InvitationStatus::Pending;
    }

    /**
     * Whether the given address is the one that was invited.
     */
    public function matches(string $email): bool
    {
        return mb_strtolower(trim($email)) === mb_strtolower($this->email);
    }

    /**
     * Create the plaintext token, returning it so it can be emailed once.
     */
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function markAccepted(User $user): void
    {
        $this->forceFill([
            'accepted_at' => now(),
            'accepted_by' => $user->getKey(),
        ])->save();
    }
}
