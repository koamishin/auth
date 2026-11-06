<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Services\OAuth\ApplicationAuthentication;
use App\Settings\ApplicationFeaturesSettings;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

/**
 * Renders the hosted sign-in screen with the appearance configured for one of
 * the application's environments, so admins can preview their branding.
 */
class HostedAppearancePreviewController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, TenantApplication $application): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canAccessPanel(Filament::getPanel('admin')) && $user->canAccessTenant($tenant), 403);
        abort_unless($application->tenant_id === $tenant->getKey(), 404);
        abort_unless($user instanceof Authorizable && $user->can('update', $application), 403);

        $environment = $request->string('environment')->toString() === 'production' ? 'production' : 'development';
        $configuration = $application->environments()->where('environment', $environment)->firstOrFail();

        $request->attributes->set('koamishin.application_environment', $configuration);

        return Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()) && app(ApplicationFeaturesSettings::class)->password_reset_enabled,
            'canRegister' => Features::enabled(Features::registration()) && app(ApplicationFeaturesSettings::class)->registration_enabled,
            'status' => $request->session()->get('status'),
            'application' => app(ApplicationAuthentication::class)->pageContext($request),
            'flow' => null,
            'hosted' => false,
            'appearancePreview' => [
                'environment' => $environment,
                'backUrl' => route('filament.admin.resources.tenant-applications.appearance', [
                    'tenant' => $tenant,
                    'record' => $application,
                ]),
            ],
        ]);
    }
}
