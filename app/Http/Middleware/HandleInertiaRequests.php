<?php

namespace App\Http\Middleware;

use App\Enums\SocialLoginProvider;
use App\Features\FeatureRegistry;
use App\Models\TenantApplicationEnvironment;
use App\Services\Auth\MultiSession;
use App\Services\OAuth\ApplicationSocialConnections;
use App\Settings\ApplicationFeaturesSettings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    #[\Override]
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function share(Request $request): array
    {
        FeatureRegistry::initialize();

        $user = $request->user();
        $settingsFeatures = [];

        if ($user) {
            $settingsFeatures = [
                'profile' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_profile'),
                'security' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_mfa_app')
                    || FeatureRegistry::isFeatureAvailableForUser($user, 'settings_mfa_email')
                    || FeatureRegistry::isFeatureAvailableForUser($user, 'settings_password')
                    || FeatureRegistry::isFeatureAvailableForUser($user, 'settings_passkeys'),
                'password' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_password'),
                'appearance' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_appearance'),
                'passkeys' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_passkeys'),
                'accountDeletion' => FeatureRegistry::isFeatureAvailableForUser($user, 'settings_account_deletion'),
            ];
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'impersonating' => app('impersonate')->isImpersonating(),
                // The accounts this browser is signed in to, for switching.
                'accounts' => fn (): array => app(MultiSession::class)->accounts($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'authLayout' => function () use ($request): string {
                $environment = $request->attributes->get('koamishin.application_environment');
                $layout = $environment instanceof TenantApplicationEnvironment ? $environment->getSetting('auth_layout') : null;

                return in_array($layout, ['simple', 'card', 'split'], true)
                    ? $layout
                    : app(ApplicationFeaturesSettings::class)->auth_layout;
            },
            'authColorTheme' => function () use ($request): ?string {
                $environment = $request->attributes->get('koamishin.application_environment');
                if (! $environment instanceof TenantApplicationEnvironment) {
                    return null;
                }

                $theme = $environment->getSetting('auth_color_theme');

                return is_string($theme) && array_key_exists($theme, TenantApplicationEnvironment::AUTH_COLOR_THEMES)
                    ? $theme
                    : 'default';
            },
            'authBackground' => function () use ($request): ?string {
                $environment = $request->attributes->get('koamishin.application_environment');
                if (! $environment instanceof TenantApplicationEnvironment) {
                    return null;
                }

                $background = $environment->getSetting('auth_background');

                return is_string($background) && array_key_exists($background, TenantApplicationEnvironment::AUTH_BACKGROUNDS)
                    ? $background
                    : null;
            },
            'notificationsEnabled' => app(ApplicationFeaturesSettings::class)->notifications_enabled,
            'settingsFeatures' => $settingsFeatures,
            'socialProviders' => function () use ($request): array {
                $environment = $request->attributes->get('koamishin.application_environment');
                $environment = $environment instanceof TenantApplicationEnvironment ? $environment : null;
                $connections = app(ApplicationSocialConnections::class);

                return collect(SocialLoginProvider::cases())
                    ->filter(fn (SocialLoginProvider $provider): bool => $connections->credentials($provider, $environment) !== null)
                    ->map(fn (SocialLoginProvider $provider): array => [
                        'slug' => $provider->value,
                        'label' => $provider->label(),
                        'url' => route('auth.social.redirect', ['provider' => $provider->value]),
                    ])->values()->all();
            },
        ];
    }
}
