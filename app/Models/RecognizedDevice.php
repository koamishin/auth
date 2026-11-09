<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A browser this account has signed in from before.
 *
 * The browser holds a device token and only its hash is stored here, so the
 * table can say "this account knows this device" without ever being able to
 * impersonate one.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $user_agent
 * @property string|null $ip_address
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property-read User $user
 */
class RecognizedDevice extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'token_hash',
        'user_agent',
        'ip_address',
        'first_seen_at',
        'last_seen_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
