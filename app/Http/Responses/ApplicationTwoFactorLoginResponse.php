<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Services\OAuth\ApplicationAuthentication;
use App\Support\FactorVerifications;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

class ApplicationTwoFactorLoginResponse extends TwoFactorLoginResponse
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        // Completing the two-factor challenge is a second-factor verification.
        FactorVerifications::recordSecondFactor($request);

        $authentication = app(ApplicationAuthentication::class);
        $flow = $authentication->flowId($request);
        if ($flow !== null) {
            $request->session()->put('koamishin.sso_mfa_verified.'.$flow, true);
        }

        if ($resume = $authentication->resume($request)) {
            return $resume;
        }

        $user = $request->user();
        $isSystemAdmin = $user instanceof User && $user->canAccessPanel(Filament::getPanel('admin'));
        if ($isSystemAdmin && $flow === null) {
            Auth::guard('portal')->login($user);
        }

        if ($request->wantsJson()) {
            return response()->json('', 204);
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
