<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TenantApplicationEnvironment;
use App\Services\OAuth\ApplicationAuthentication;
use App\Support\VerificationDevice;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects an email verification link that was opened on a different device or
 * browser from the one that requested it.
 *
 * Only enforced when the application has device binding on and the link
 * actually carries a device fingerprint, so links issued elsewhere (the portal
 * itself, or links sent before this was enabled) still work.
 */
class EnforceVerificationLinkDevice
{
    public function __construct(private readonly VerificationDevice $device) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('verification.verify')) {
            return $next($request);
        }

        $fingerprint = $request->query('device');

        if (! is_string($fingerprint) || $fingerprint === '') {
            return $next($request);
        }

        $environment = $request->attributes->get('koamishin.application_environment');

        if ($environment instanceof TenantApplicationEnvironment && ! $environment->bindsVerificationLinksToDevice()) {
            return $next($request);
        }

        if (! $this->device->matches($request, $fingerprint)) {
            // An unrecognized device gets the hosted explanation rather than a
            // bare error: the attempt was cancelled and nothing was signed in.
            return Inertia::render('auth/UnauthorizedSignIn', [
                'application' => app(ApplicationAuthentication::class)->pageContext($request),
                'flow' => app(ApplicationAuthentication::class)->flowId($request),
            ])->toResponse($request)->setStatusCode(403);
        }

        return $next($request);
    }
}
