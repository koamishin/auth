<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Services\OAuth\ApplicationAuthentication;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

class ApplicationLoginResponse extends LoginResponse
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        if ($resume = app(ApplicationAuthentication::class)->resume($request)) {
            return $resume;
        }

        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
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
