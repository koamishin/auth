<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Settings\ApplicationSecuritySettings;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session once it has been idle too long, or once it has lived longer
 * than its maximum lifetime.
 *
 * The limits are pinned to the session the first time it is used, so changing an
 * application's session settings only affects sessions created afterwards.
 * Existing sessions keep the limits they started with, which is what an
 * administrator lowering a limit would expect.
 */
class EnforceSessionLifetime
{
    /** Session key holding the limits pinned to this session. */
    public const SESSION_KEY = 'koamishin.session_lifetime';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $session = $request->session();
        $pinned = $session->get(self::SESSION_KEY);

        if (! is_array($pinned)) {
            $session->put(self::SESSION_KEY, $pinned = $this->pin());
        }

        $now = now()->timestamp;
        $startedAt = is_int($pinned['started_at'] ?? null) ? $pinned['started_at'] : $now;
        $lastSeenAt = is_int($pinned['last_seen_at'] ?? null) ? $pinned['last_seen_at'] : $now;

        if ($this->hasExpired($pinned, $startedAt, $lastSeenAt, $now)) {
            $this->endSession($request);

            return $request->header('X-Inertia')
                ? Inertia::location(route('login'))
                : redirect()->route('login')->with('status', 'Your session expired. Please sign in again.');
        }

        $pinned['last_seen_at'] = $now;
        $session->put(self::SESSION_KEY, $pinned);

        return $next($request);
    }

    /**
     * The limits that apply to a session starting now.
     *
     * @return array{idle: int|null, max: int|null, started_at: int, last_seen_at: int}
     */
    private function pin(): array
    {
        $environment = ApplicationContext::environment();
        $now = now()->timestamp;

        if ($environment instanceof TenantApplicationEnvironment) {
            $idle = $environment->sessionInactivityTimeout();
            $max = $environment->sessionMaxLifetime();
        } else {
            // Portal sessions fall back to the organization's own security setting.
            $settings = app(ApplicationSecuritySettings::class);
            $idle = null;
            $max = (int) $settings->session_lifetime > 0 ? (int) $settings->session_lifetime : null;
        }

        return [
            'idle' => $idle,
            'max' => $max,
            'started_at' => $now,
            'last_seen_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $pinned
     */
    private function hasExpired(array $pinned, int $startedAt, int $lastSeenAt, int $now): bool
    {
        $max = is_int($pinned['max'] ?? null) ? $pinned['max'] : null;
        $idle = is_int($pinned['idle'] ?? null) ? $pinned['idle'] : null;

        if ($max !== null && ($now - $startedAt) > $max * 60) {
            return true;
        }

        return $idle !== null && ($now - $lastSeenAt) > $idle * 60;
    }

    private function endSession(Request $request): void
    {
        $guard = $request->attributes->get('koamishin.application_guard');

        if ($guard instanceof StatefulGuard) {
            $guard->logout();
        } else {
            Auth::guard(config('fortify.guard', 'web'))->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
