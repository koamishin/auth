<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Services\OAuth\ApplicationAuthentication;
use Filament\Facades\Filament;
use Laravel\Passkeys\Http\Responses\PasskeyLoginResponse;
use Symfony\Component\HttpFoundation\Response;

class ApplicationPasskeyLoginResponse extends PasskeyLoginResponse
{
    public function toResponse($request): Response
    {
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
