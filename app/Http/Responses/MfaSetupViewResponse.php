<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationAuthentication;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

/**
 * The screen shown when an application requires a second factor and the signed
 * in user has not enrolled one yet.
 */
class MfaSetupViewResponse implements Responsable
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $user = $request->user();
        $environment = $request->attributes->get('koamishin.application_environment');

        return Inertia::render('auth/MfaSetup', [
            'application' => $environment instanceof TenantApplicationEnvironment
                ? [
                    'name' => $environment->application->name,
                    'environment' => $environment->environment,
                ]
                : null,
            'flow' => app(ApplicationAuthentication::class)->flowId($request),
            'hosted' => app(ApplicationAuthentication::class)->isHosted($request),
            'requiresConfirmation' => (bool) config('fortify.features')
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
            'continueUrl' => $this->continueUrl($request),
            'enrolled' => $user instanceof User && $user->hasEnrolledSecondFactor(),
        ])->toResponse($request);
    }

    /**
     * Where the user goes once a factor is enrolled: back into the authorization
     * request they were completing.
     */
    private function continueUrl(Request $request): string
    {
        $flow = $request->attributes->get('koamishin.application_flow');

        if (is_array($flow) && is_string($flow['authorize_url'] ?? null)) {
            return $flow['authorize_url'];
        }

        return route('dashboard', absolute: false);
    }
}
