<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One address waiting for early access to an application.
 *
 * Joining a waitlist is a statement of interest only: it grants nothing until
 * an operator sends an invitation.
 *
 * @property int $id
 * @property int $tenant_application_environment_id
 * @property string $email
 * @property Carbon|null $invited_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TenantApplicationEnvironment $environment
 */
class ApplicationWaitlistEntry extends Model
{
    protected $fillable = [
        'tenant_application_environment_id',
        'email',
        'invited_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'invited_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<TenantApplicationEnvironment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(TenantApplicationEnvironment::class, 'tenant_application_environment_id');
    }

    /**
     * Addresses that have not been invited yet.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereNull('invited_at');
    }
}
