<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\OAuth\ApplicationAuthentication;
use Closure;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfApplicationFlowAuthenticated extends RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        // Signing in as an additional account has to be possible while already
        // authenticated: multi-session keeps the current session until the new
        // sign-in completes. The opt-in is explicit in the query string.
        if ($request->boolean('add')) {
            return $next($request);
        }

        $guard = $request->attributes->get('koamishin.application_guard');
        if ($guard instanceof StatefulGuard) {
            return $guard->check()
                ? app(ApplicationAuthentication::class)->resume($request)
                : $next($request);
        }

        return parent::handle($request, $next, ...$guards);
    }
}
