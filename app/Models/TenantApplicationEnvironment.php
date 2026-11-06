<?php

declare(strict_types=1);

namespace App\Models;

use App\Settings\ApplicationFeaturesSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Client;

/**
 * @property int $id
 * @property int $tenant_application_id
 * @property string $environment
 * @property string|null $oauth_client_id
 * @property string|null $publishable_key
 * @property string|null $secret_key_lookup
 * @property string|null $secret_key_hash
 * @property string|null $secret_key_prefix
 * @property Carbon|null $secret_key_rotated_at
 * @property string|null $client_type
 * @property array<string>|null $redirect_uris
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TenantApplication|null $application
 * @property-read Client|null $oauthClient
 */
class TenantApplicationEnvironment extends Model
{
    use HasFactory;

    public const AUTH_COLOR_THEMES = [
        'default' => 'Default',
        'rose' => 'Rose',
        'ocean' => 'Ocean',
        'sage-garden' => 'Sage Garden',
        'claude' => 'Claude',
    ];

    public const AUTH_BACKGROUNDS = [
        'gradient' => 'Soft gradient',
        'spotlight' => 'Spotlight',
        'grid' => 'Subtle grid',
    ];

    protected $fillable = [
        'tenant_application_id',
        'environment',
        'oauth_client_id',
        'publishable_key',
        'secret_key_lookup',
        'secret_key_hash',
        'secret_key_prefix',
        'secret_key_rotated_at',
        'client_type',
        'redirect_uris',
        'settings',
    ];

    protected $hidden = [
        'secret_key_lookup',
        'secret_key_hash',
    ];

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'settings' => 'array',
            'secret_key_rotated_at' => 'datetime',
        ];
    }

    public static function generatePublishableKey(string $environment): string
    {
        $prefix = $environment === 'production' ? 'pk_live_' : 'pk_test_';

        do {
            $candidate = $prefix.Str::random(32);
        } while (self::query()->where('publishable_key', $candidate)->exists());

        return $candidate;
    }

    /**
     * @return array{plain: string, lookup: string, hash: string, prefix: string}
     */
    public static function generateSecretKeyMaterial(string $environment): array
    {
        $prefix = $environment === 'production' ? 'sk_live_' : 'sk_test_';

        do {
            $plain = $prefix.Str::random(40);
            $lookup = hash('sha256', $plain);
        } while (self::query()->where('secret_key_lookup', $lookup)->exists());

        return [
            'plain' => $plain,
            'lookup' => $lookup,
            'hash' => Hash::make($plain),
            'prefix' => substr($plain, 0, 12).'...'.substr($plain, -4),
        ];
    }

    public function verifySecretKey(string $plainSecret): bool
    {
        $expectedPrefix = $this->isProduction() ? 'sk_live_' : 'sk_test_';

        if ($plainSecret === '' || ! str_starts_with($plainSecret, $expectedPrefix)) {
            return false;
        }

        if (! is_string($this->secret_key_lookup) || $this->secret_key_lookup === ''
            || ! is_string($this->secret_key_hash) || $this->secret_key_hash === '') {
            return false;
        }

        return hash_equals($this->secret_key_lookup, hash('sha256', $plainSecret))
            && Hash::check($plainSecret, $this->secret_key_hash);
    }

    public function isConnected(): bool
    {
        return in_array($this->client_type, ['public', 'confidential'], true)
            && count($this->redirect_uris ?? []) > 0
            && $this->oauthClient !== null
            && ! $this->oauthClient->getAttribute('revoked');
    }

    /**
     * @return BelongsTo<TenantApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(TenantApplication::class, 'tenant_application_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function isDevelopment(): bool
    {
        return $this->environment === 'development';
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function requiresEmailVerification(): bool
    {
        $applicationSetting = $this->getSetting('email_verification_required');

        return is_bool($applicationSetting)
            ? $applicationSetting
            : app(ApplicationFeaturesSettings::class)->email_verification_required;
    }

    public function allowsPasskeys(): bool
    {
        return $this->getSetting('passkeys_enabled', true) === true;
    }

    public function allowsSingleSignOn(): bool
    {
        return $this->getSetting('sso_enabled', false) === true;
    }

    public function requiresOAuthConsent(): bool
    {
        return $this->getSetting('oauth_consent_enabled', false) === true;
    }

    public function identityNamespace(): string
    {
        return 'environment:'.$this->getKey();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'tenant_application_environment_id');
    }
}
