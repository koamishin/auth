<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Services\OAuth\ApplicationAuthentication;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

class ApplicationTwoFactorLoginResponse extends TwoFactorLoginResponse
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        $authentication = app(ApplicationAuthentication::class);
        $flow = $authentication->flowId($request);
        if ($flow !== null) {
            $request->session()->put('koamishin.sso_mfa_verified.'.$flow, true);
        }

        if ($resume = $authentication->resume($request)) {
            return $resume;
        }

        if ($request->wantsJson()) {
            return response()->json('', 204);
        }

        $intended = $request->session()->get('url.intended');
        if ($intended !== null && str_contains($intended, '/dashboard')) {
            $request->session()->forget('url.intended');
        }

        $redirect = redirect()->intended(Fortify::redirects('login'));

        return $request->header('X-Inertia')
            ? Inertia::location($redirect->getTargetUrl())
            : $redirect;
    }
}
