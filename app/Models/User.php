<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailCodePurpose;
use App\Enums\SecondFactor;
use App\Services\Auth\EmailCodeService;
use App\Services\OAuth\ApplicationAuthentication;
use App\Settings\ApplicationFeaturesSettings;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\Email\Concerns\InteractsWithEmailAuthentication;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $profile_photo_path
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $banned_at
 * @property string|null $ban_reason
 * @property string|null $username
 * @property string|null $phone_number
 * @property Carbon|null $phone_verified_at
 * @property string|null $web3_wallet
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $two_factor_secret
 * @property string|null $app_authentication_secret
 * @property bool $has_email_authentication
 * @property-read Collection<int, SocialAccount> $socialAccounts
 * @property-read Collection<int, Tenant> $tenants
 * @property-read Collection<int, Session> $sessions
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasEmailAuthentication, HasTenants, MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Impersonate, Notifiable;

    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use InteractsWithEmailAuthentication;
    use LogsActivity {
        shouldLogEvent as protected shouldLogActivityEvent;
    }
    use PasskeyAuthenticatable;
    use TwoFactorAuthenticatable;

    public const PORTAL_IDENTITY_NAMESPACE = 'portal';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'identity_namespace' => self::PORTAL_IDENTITY_NAMESPACE,
    ];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->tenant_application_environment_id !== null && ($user->identity_namespace === null || $user->identity_namespace === self::PORTAL_IDENTITY_NAMESPACE)) {
                $user->identity_namespace = 'environment:'.$user->tenant_application_environment_id;
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isSystemAdmin() && ! $this->isBanned();
    }

    public function isSystemAdmin(): bool
    {
        return ($this->identity_namespace ?? self::PORTAL_IDENTITY_NAMESPACE) === self::PORTAL_IDENTITY_NAMESPACE
            && $this->hasRole('super_admin');
    }

    public function isApplicationUser(): bool
    {
        return ! $this->isSystemAdmin();
    }

    /**
     * @return BelongsTo<TenantApplicationEnvironment, $this>
     */
    public function tenantApplicationEnvironment(): BelongsTo
    {
        return $this->belongsTo(TenantApplicationEnvironment::class, 'tenant_application_environment_id');
    }

    public function canImpersonate(): bool
    {
        return app(ApplicationFeaturesSettings::class)->user_impersonation_enabled && $this->hasRole('admin');
    }

    protected function shouldLogEvent(string $eventName): bool
    {
        return app(ApplicationFeaturesSettings::class)->activity_log_enabled && $this->shouldLogActivityEvent($eventName);
    }

    public function sendEmailVerificationNotification(): void
    {
        $environment = request()->attributes->get('koamishin.application_environment');
        if ($environment instanceof TenantApplicationEnvironment
            ? ! $environment->requiresEmailVerification()
            : ! app(ApplicationFeaturesSettings::class)->email_verification_required) {
            return;
        }

        // Applications can verify with a link or with a one-time code. The link
        // carries a device fingerprint so it can only be used where it was asked
        // for; codes are issued and checked by the email code service.
        if ($environment instanceof TenantApplicationEnvironment && ! $environment->usesEmailVerificationLink()) {
            app(EmailCodeService::class)->send($this, EmailCodePurpose::Verification);
        } else {
            $this->notify(new VerifyEmail);
        }

        $flow = app(ApplicationAuthentication::class)->flowId(request());
        if ($flow !== null) {
            request()->session()->put('koamishin.verification_sent.'.$flow, true);
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    public function canBeImpersonated(): bool
    {
        return ! $this->hasRole('admin');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'phone_number',
        'phone_verified_at',
        'web3_wallet',
        'metadata',
        'password',
        'profile_photo_path',
        'banned_at',
        'ban_reason',
        'identity_namespace',
        'tenant_application_environment_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the user's profile photo URL.
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        if ($this->profile_photo_path) {
            return asset('storage/'.$this->profile_photo_path);
        }

        return null;
    }

    /**
     * Get the disk that profile photos should be stored on.
     */
    protected function profilePhotoDisk(): string
    {
        return 'public';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'banned_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'has_email_authentication' => 'boolean',
            'metadata' => 'array',
            'password' => 'hashed',
            'phone_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Session, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function hasWeb3Wallet(): bool
    {
        return filled($this->web3_wallet);
    }

    public function hasMfa(): bool
    {
        return $this->two_factor_confirmed_at !== null
            || filled($this->app_authentication_secret)
            || $this->has_email_authentication;
    }

    /**
     * Whether a second factor has actually been enrolled and confirmed.
     */
    public function hasEnrolledSecondFactor(): bool
    {
        return $this->hasMfa();
    }

    /**
     * The second factors this user has enrolled.
     *
     * @return list<SecondFactor>
     */
    public function enrolledSecondFactors(): array
    {
        $factors = [];

        if ($this->two_factor_confirmed_at !== null || filled($this->app_authentication_secret)) {
            $factors[] = SecondFactor::AuthenticatorApp;
        }

        if ((bool) $this->has_email_authentication) {
            $factors[] = SecondFactor::EmailCode;
        }

        return $factors;
    }

    public function hasRecoveryCodes(): bool
    {
        return filled($this->two_factor_recovery_codes) || filled($this->app_authentication_recovery_codes);
    }

    /**
     * Remove an enrolled second factor, clearing the recovery codes that belong
     * to it. Removing the last factor leaves the account without multi-factor
     * authentication, which is what an administrator resetting access expects.
     */
    public function removeSecondFactor(SecondFactor $factor): void
    {
        match ($factor) {
            SecondFactor::AuthenticatorApp => $this->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'app_authentication_secret' => null,
                'app_authentication_recovery_codes' => null,
            ])->save(),
            SecondFactor::EmailCode => $this->forceFill([
                'has_email_authentication' => false,
            ])->save(),
        };
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<TenantApplication, $this>
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(TenantApplication::class, 'tenant_application_user')
            ->withPivot(['role', 'environment', 'last_sign_in_at'])
            ->withTimestamps();
    }

    /**
     * @return Collection<int, Model>
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->hasRole('super_admin')) {
            return Tenant::query()->get();
        }

        return $this->tenants;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        return $this->tenants()->whereKey($tenant)->exists();
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function ban(?string $reason = null): void
    {
        $this->forceFill([
            'banned_at' => now(),
            'ban_reason' => $reason,
        ])->save();

        $this->revokeAllSessions();
    }

    public function unban(): void
    {
        $this->forceFill([
            'banned_at' => null,
            'ban_reason' => null,
        ])->save();
    }

    public function revokeAllSessions(): void
    {
        DB::table('sessions')->where('user_id', $this->id)->delete();

        DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', $this->tokens()->select('id'))
            ->update(['revoked' => true]);

        $this->tokens()->update(['revoked' => true]);
    }
}
