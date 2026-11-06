<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves the application environment a request belongs to.
 *
 * Authorization requests carry it as a request attribute, but ordinary pages
 * such as profile settings do not. For a signed in application user the
 * environment is known from their own account, which keeps per-application
 * settings working on those pages too.
 */
final class ApplicationContext
{
    public static function environment(?Request $request = null): ?TenantApplicationEnvironment
    {
        $request ??= request();

        $environment = $request?->attributes?->get('koamishin.application_environment');

        if ($environment instanceof TenantApplicationEnvironment) {
            return $environment;
        }

        $user = $request?->user();

        if ($user instanceof User) {
            $environment = $user->tenantApplicationEnvironment;

            return $environment instanceof TenantApplicationEnvironment ? $environment : null;
        }

        return null;
    }
}
