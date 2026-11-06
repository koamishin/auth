<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Services\OAuth\ApplicationAuthentication;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Http\Responses\PasskeyLoginResponse;
use Symfony\Component\HttpFoundation\Response;

class ApplicationPasskeyLoginResponse extends PasskeyLoginResponse
{
    /** @param Request $request */
    public function toResponse($request): Response
    {
        // A passkey is a device plus a biometric or PIN, so this sign-in already
        // meets a multi-factor requirement.
        app(ApplicationAuthentication::class)->markSignInSatisfiesMultiFactor($request, true);

        $flow = $request->attributes->get('koamishin.application_flow');
        if (is_array($flow) && $request->wantsJson()) {
            if (app(ApplicationAuthentication::class)->isHosted($request)) {
                return response()->json(['hosted' => true]);
            }

            return response()->json(['redirect' => $flow['authorize_url']]);
        }

        if ($resume = app(ApplicationAuthentication::class)->resume($request)) {
            return $resume;
        }

        $user = $request->user();
        $isSystemAdmin = $user instanceof User && $user->canAccessPanel(Filament::getPanel('admin'));
        if ($isSystemAdmin && ! is_array($flow)) {
            Auth::guard('portal')->login($user);
        }

        $intended = $request->session()->get('url.intended');
        if ($intended !== null) {
            if ($isSystemAdmin && str_contains($intended, '/dashboard')) {
                $request->session()->forget('url.intended');
            } elseif (! $isSystemAdmin && str_contains($intended, '/admin')) {
                $request->session()->forget('url.intended');
            }
        }

        $target = $isSystemAdmin ? '/admin' : config('passkeys.redirect', '/dashboard');

        if ($request->wantsJson()) {
            return response()->json([
                'redirect' => redirect()->intended($target)->getTargetUrl(),
            ], 200);
        }

        return redirect()->intended($target);
    }
}
