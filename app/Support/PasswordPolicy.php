<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TenantApplicationEnvironment;
use Illuminate\Validation\Rules\Password;

/**
 * Resolves the password requirements for the application environment in
 * context, so a minimum length configured per application is actually enforced
 * rather than only displayed.
 */
final class PasswordPolicy
{
    /**
     * Minimum length the platform enforces in production, regardless of the
     * application's own setting.
     */
    public const PRODUCTION_MINIMUM = 12;

    /**
     * Minimum length used outside production and as the base rule.
     */
    public const FALLBACK_MINIMUM = 8;

    /**
     * The rule applied to any password being created or changed.
     */
    public static function rule(?TenantApplicationEnvironment $environment = null): Password
    {
        $platform = Password::default();

        $rule = $platform instanceof Password ? $platform : Password::min(self::FALLBACK_MINIMUM);

        // `minimum()` never returns less than the platform default, so applying
        // it can only raise the requirement.
        return $rule->min(self::minimum($environment));
    }

    /**
     * The effective minimum length: the stricter of the platform default and
     * the application's configured value.
     */
    public static function minimum(?TenantApplicationEnvironment $environment = null): int
    {
        $platform = app()->isProduction() ? self::PRODUCTION_MINIMUM : self::FALLBACK_MINIMUM;

        return max($platform, $environment?->minimumPasswordLength() ?? self::FALLBACK_MINIMUM);
    }

    /**
     * The rule for the application environment attached to the current request.
     */
    public static function forCurrentRequest(): Password
    {
        return self::rule(self::environment());
    }

    /**
     * The human readable summary sent to the frontend for password hints.
     */
    public static function rulesForFrontend(): string
    {
        return self::forCurrentRequest()->toPasswordRulesString();
    }

    public static function environment(): ?TenantApplicationEnvironment
    {
        $environment = request()?->attributes?->get('koamishin.application_environment');

        return $environment instanceof TenantApplicationEnvironment ? $environment : null;
    }

    /**
     * Whether passwords may be created for the application in context. Disabling
     * password authentication stops new passwords from being set; it deliberately
     * does not lock out users who already have one.
     */
    public static function allowsPasswordCreation(): bool
    {
        return self::environment()?->allowsPasswordAuthentication() ?? true;
    }
}
