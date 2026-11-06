<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Services\OAuth\ApplicationAuthentication;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

class ApplicationLoginResponse extends LoginResponse
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        // A password sign-in is not multi-factor on its own, so any passkey
        // satisfaction from earlier in this session must not carry over.
        app(ApplicationAuthentication::class)->markSignInSatisfiesMultiFactor($request, false);

        if ($resume = app(ApplicationAuthentication::class)->resume($request)) {
            return $resume;
        }

        $user = $request->user();
        $isSystemAdmin = $user instanceof User && $user->canAccessPanel(Filament::getPanel('admin'));
        if ($isSystemAdmin && $request->attributes->get('koamishin.application_flow') === null) {
            Auth::guard('portal')->login($user);
        }

        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $intended = $request->session()->get('url.intended');
        if ($intended !== null) {
            if ($isSystemAdmin && str_contains($intended, '/dashboard')) {
                $request->session()->forget('url.intended');
            } elseif (! $isSystemAdmin && str_contains($intended, '/admin')) {
                $request->session()->forget('url.intended');
            }
        }

        $destination = $isSystemAdmin
            ? Fortify::redirects('login')
            : config('fortify.home', '/dashboard');

        $redirect = redirect()->intended($destination);

        return $request->header('X-Inertia')
            ? Inertia::location($redirect->getTargetUrl())
            : $redirect;
    }
}
