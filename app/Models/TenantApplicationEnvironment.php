<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessMode;
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

    /**
     * Verification channels a user can complete an email challenge with.
     *
     * @var array<string, string>
     */
    public const EMAIL_VERIFICATION_METHODS = [
        'link' => 'Email verification link',
        'code' => 'Email verification code',
    ];

    /** How long a verification link stays valid, in minutes. */
    public const DEFAULT_VERIFICATION_LINK_TTL = 10;

    /** Maximum session lifetime applied when an application does not set one. */
    public const DEFAULT_SESSION_MAX_LIFETIME = 10080;

    /**
     * Supported metadata attribute types for the application user model.
     *
     * @var array<string, string>
     */
    public const METADATA_ATTRIBUTE_TYPES = [
        'string' => 'Text',
        'number' => 'Number',
        'boolean' => 'True / false',
        'date' => 'Date',
    ];

    /**
     * Visibility scopes for user metadata attributes.
     *
     * @var array<string, string>
     */
    public const METADATA_ATTRIBUTE_VISIBILITIES = [
        'public' => 'Public — returned in tokens and the API',
        'private' => 'Private — readable by the application backend only',
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

    public function allowsEmailSignup(): bool
    {
        return $this->getSetting('auth_email_signup', true) === true;
    }

    /**
     * How users may gain access to this application.
     *
     * Falls back to the older boolean setting so applications created before
     * access modes existed keep behaving exactly as they did.
     */
    public function accessMode(): AccessMode
    {
        $mode = $this->getSetting('access_mode');

        if (is_string($mode)) {
            $resolved = AccessMode::tryFrom($mode);

            if ($resolved instanceof AccessMode) {
                return $resolved;
            }
        }

        return AccessMode::fromLegacySignups($this->getSetting('allow_signups', false) === true);
    }

    /**
     * Whether someone may create their own account here.
     */
    public function allowsPublicSignup(): bool
    {
        return $this->accessMode()->allowsPublicSignup();
    }

    public function blocksEmailSubaddresses(): bool
    {
        return $this->getSetting('block_email_subaddresses', false) === true;
    }

    /**
     * Minutes of inactivity after which a session expires, or null when there is
     * no inactivity limit.
     */
    public function sessionInactivityTimeout(): ?int
    {
        return $this->positiveMinutes($this->getSetting('session_inactivity_timeout'));
    }

    /**
     * Minutes after which a session expires regardless of activity, or null when
     * there is no maximum lifetime.
     */
    public function sessionMaxLifetime(): ?int
    {
        return $this->positiveMinutes($this->getSetting('session_max_lifetime', self::DEFAULT_SESSION_MAX_LIFETIME));
    }

    /**
     * A setting holding a whole number of minutes, or null when unset or zero.
     */
    private function positiveMinutes(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $minutes = (int) $value;

        return $minutes > 0 ? $minutes : null;
    }

    /**
     * Whether users of this application may delete their own accounts.
     */
    public function allowsAccountDeletion(): bool
    {
        return $this->getSetting('allow_account_deletion', true) === true;
    }

    /**
     * Whether this application collects a first and last name rather than a
     * single display name.
     */
    public function collectsNameParts(): bool
    {
        return $this->getSetting('allow_name_parts', false) === true;
    }

    public function blocksDisposableEmails(): bool
    {
        return $this->getSetting('block_disposable_emails', false) === true;
    }

    /**
     * Allow and block rules for identifiers, as `{type, kind, value}` entries.
     *
     * @return list<array{type: string, kind: string, value: string}>
     */
    public function accessRules(): array
    {
        $rules = $this->getSetting('access_rules', []);

        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $type = $rule['type'] ?? null;
            $kind = $rule['kind'] ?? null;
            $value = $rule['value'] ?? null;

            if (! is_string($type) || ! in_array($type, ['allow', 'block'], true)) {
                continue;
            }

            if (! is_string($kind) || ! in_array($kind, ['email', 'domain'], true)) {
                continue;
            }

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'kind' => $kind,
                'value' => $kind === 'email' ? mb_strtolower(trim($value)) : mb_strtolower(ltrim(trim($value), '@')),
            ];
        }

        return $normalized;
    }

    public function allowsEmailSignin(): bool
    {
        return $this->getSetting('auth_email_signin', true) === true;
    }

    public function allowsPasswordAuthentication(): bool
    {
        return $this->getSetting('auth_password_enabled', true) === true;
    }

    /**
     * Minimum length required for passwords created in this environment.
     */
    public function minimumPasswordLength(): int
    {
        $length = $this->getSetting('auth_password_min_length', 8);

        if (! is_numeric($length)) {
            return 8;
        }

        $length = (int) $length;

        return $length >= 8 && $length <= 128 ? $length : 8;
    }

    public function emailVerificationMethod(): string
    {
        $method = $this->getSetting('email_verification_method', 'link');

        return is_string($method) && array_key_exists($method, self::EMAIL_VERIFICATION_METHODS) ? $method : 'link';
    }

    /**
     * Whether a verification link (rather than a code) is used.
     */
    public function usesEmailVerificationLink(): bool
    {
        return $this->emailVerificationMethod() === 'link';
    }

    /**
     * How long a verification link stays valid, in minutes.
     */
    public function emailVerificationLinkTtl(): int
    {
        $ttl = $this->getSetting('email_verification_link_ttl', self::DEFAULT_VERIFICATION_LINK_TTL);

        if (! is_numeric($ttl)) {
            return self::DEFAULT_VERIFICATION_LINK_TTL;
        }

        $ttl = (int) $ttl;

        return $ttl >= 5 && $ttl <= 1440 ? $ttl : self::DEFAULT_VERIFICATION_LINK_TTL;
    }

    /**
     * Whether users may sign in with a one-time code sent to their email.
     */
    public function allowsEmailCodeSignin(): bool
    {
        return $this->getSetting('auth_email_code_signin', false) === true;
    }

    /**
     * Whether this environment requires every user to have a second factor
     * enrolled before they can finish signing in.
     */
    public function requiresMultiFactorAuthentication(): bool
    {
        return $this->getSetting('require_mfa', false) === true;
    }

    /**
     * Whether signing in with a passkey counts as multi-factor on its own.
     *
     * A passkey combines a physical device with a biometric or PIN check, so it
     * satisfies the requirement unless the application insists on enrolment.
     */
    public function passkeysSatisfyMultiFactor(): bool
    {
        return $this->getSetting('passkeys_satisfy_mfa', true) === true;
    }

    /**
     * Whether a verification link must be opened on the device that requested it.
     */
    public function bindsVerificationLinksToDevice(): bool
    {
        return $this->getSetting('email_verification_bind_device', true) === true;
    }

    /**
     * Whether built-in authenticators (Touch ID, Face ID, Windows Hello) may be
     * used to create passkeys for this environment.
     */
    public function allowsPlatformAuthenticators(): bool
    {
        return $this->getSetting('passkey_platform_authenticators', true) === true;
    }

    /**
     * Whether passkey ceremonies must verify the user (biometric or PIN) rather
     * than merely test for the authenticator's presence.
     */
    public function requiresPasskeyUserVerification(): bool
    {
        return $this->getSetting('passkey_require_user_verification', true) === true;
    }

    /**
     * Custom metadata attributes defined for the application user model.
     *
     * @return list<array{key: string, type: string, visibility: string}>
     */
    public function userMetadataAttributes(): array
    {
        $attributes = $this->getSetting('user_metadata_attributes', []);

        if (! is_array($attributes)) {
            return [];
        }

        $normalized = [];

        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || ! is_string($attribute['key'] ?? null) || trim($attribute['key']) === '') {
                continue;
            }

            $type = $attribute['type'] ?? 'string';
            $visibility = $attribute['visibility'] ?? 'public';

            $normalized[] = [
                'key' => trim($attribute['key']),
                'type' => is_string($type) && array_key_exists($type, self::METADATA_ATTRIBUTE_TYPES) ? $type : 'string',
                'visibility' => is_string($visibility) && array_key_exists($visibility, self::METADATA_ATTRIBUTE_VISIBILITIES) ? $visibility : 'public',
            ];
        }

        return $normalized;
    }

    /**
     * Validate metadata fields against the application's attribute definitions.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, string> Field name mapped to a validation message.
     */
    public function validateMetadata(array $fields): array
    {
        $attributes = collect($this->userMetadataAttributes())->keyBy('key');

        if ($attributes->isEmpty()) {
            return [];
        }

        $errors = [];

        foreach ($fields as $scope => $values) {
            if (! in_array($scope, ['public', 'private'], true)) {
                continue;
            }

            if (is_object($values)) {
                $values = get_object_vars($values);
            }

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $key => $value) {
                $attribute = $attributes->get($key);

                if ($attribute === null) {
                    $errors[$scope.'.'.$key] = "The attribute [{$key}] is not defined for this application.";

                    continue;
                }

                if ($attribute['visibility'] !== $scope) {
                    $errors[$scope.'.'.$key] = "The attribute [{$key}] is defined as {$attribute['visibility']} metadata.";

                    continue;
                }

                if (! $this->metadataValueMatchesType($value, $attribute['type'])) {
                    $errors[$scope.'.'.$key] = "The attribute [{$key}] must be a {$attribute['type']} value.";
                }
            }
        }

        return $errors;
    }

    private function metadataValueMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'date' => is_string($value) && strtotime($value) !== false,
            default => is_string($value),
        };
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
