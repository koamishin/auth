<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A switch token granting this browser access to one account.
 *
 * One row is created every time an account is signed in to on a browser. The
 * raw token only ever lives in that browser's encrypted cookie; the database
 * keeps its hash, so a leaked database cannot be used to switch into accounts.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 * @property Carbon|null $last_used_at
 * @property Carbon $expires_at
 * @property-read User $user
 */
class MultiSessionAccount extends Model
{
    /** A token ledger with its own timestamps, like the sessions table. */
    public $timestamps = false;

    public $incrementing = true;

    protected $fillable = [
        'user_id',
        'token_hash',
        'ip_address',
        'user_agent',
        'created_at',
        'last_used_at',
        'expires_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'created_at' => 'datetime',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
