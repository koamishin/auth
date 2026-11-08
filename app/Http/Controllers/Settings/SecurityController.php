<?php

namespace App\Http\Controllers\Settings;

use App\Features\FeatureRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\Session;
use App\Models\User;
use App\Settings\ApplicationFeaturesSettings;
use App\Support\PasswordPolicy;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\PanelRegistry;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    public function edit(TwoFactorAuthenticationRequest $twoFactorAuthenticationRequest): Response
    {
        FeatureRegistry::initialize();

        filament()->setCurrentPanel('admin');

        $panel = app(PanelRegistry::class)->get('admin');
        $providers = $panel?->getMultiFactorAuthenticationProviders() ?? [];

        $appProvider = $providers['app'] ?? null;
        $emailProvider = $providers['email_code'] ?? null;

        $user = $twoFactorAuthenticationRequest->user();
        $mfaEnabled = app(ApplicationFeaturesSettings::class)->two_factor_authentication_enabled;
        $mfaAppAvailable = $mfaEnabled && FeatureRegistry::isFeatureAvailableForUser($user, 'settings_mfa_app');
        $mfaEmailAvailable = $mfaEnabled && FeatureRegistry::isFeatureAvailableForUser($user, 'settings_mfa_email');

        $props = [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'emailVerified' => $user instanceof MustVerifyEmail ? $user->hasVerifiedEmail() : true,
            'availableFeatures' => [
                'mfaApp' => $mfaAppAvailable,
                'mfaEmail' => $mfaEmailAvailable,
            ],
            'filamentMfa' => [
                'providers' => [
                    'app' => $mfaAppAvailable && ($appProvider instanceof AppAuthentication),
                    'email' => $mfaEmailAvailable && ($emailProvider instanceof EmailAuthentication),
                ],
                'state' => [
                    'app' => method_exists($user, 'getAppAuthenticationSecret') && filled($user->getAppAuthenticationSecret()),
                    'email' => method_exists($user, 'hasEmailAuthentication') && $user->hasEmailAuthentication(),
                ],
                'options' => [
                    'appRecoveryCodes' => $appProvider instanceof AppAuthentication && $appProvider->isRecoverable(),
                ],
            ],
            'canManageTwoFactor' => $mfaEnabled && Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $twoFactorAuthenticationRequest->user()
                    ->passkeys()
                    ->latest()
                    ->get()
                    ->map(fn ($passkey): array => [
                        'id' => (string) $passkey->getKey(),
                        'name' => $passkey->name,
                        'authenticator' => $passkey->authenticator,
                        'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                        'created_at' => $passkey->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all()
                : [],
            'passwordRules' => PasswordPolicy::rulesForFrontend(),
            'devices' => $this->devices($twoFactorAuthenticationRequest, $user),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $twoFactorAuthenticationRequest->ensureStateIsValid();

            $props['twoFactorEnabled'] = $twoFactorAuthenticationRequest->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $passwordUpdateRequest): RedirectResponse
    {
        FeatureRegistry::initialize();

        $user = $passwordUpdateRequest->user();

        if (! FeatureRegistry::isFeatureAvailableForUser($user, 'settings_password')) {
            return back()->with('error', 'Password changes are not available for your role');
        }

        $user->update([
            'password' => $passwordUpdateRequest->password,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }

    /**
     * Sign one device out by revoking its browser session.
     */
    public function destroyDevice(Request $request, string $session): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = Session::query()->where('user_id', $user->getKey())->findOrFail($session);
        $record->delete();

        if ($session === $request->session()->getId()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device signed out.')]);

        return to_route('security.edit');
    }

    /**
     * Sign every device out except this one.
     */
    public function destroyOtherDevices(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Other devices signed out.')]);

        return to_route('security.edit');
    }

    /**
     * The browser sessions the account is signed in on.
     *
     * @return array<int, array{id: string, device: string, ip: string|null, last_active: string, current: bool}>
     */
    private function devices(Request $request, User $user): array
    {
        $current = $request->session()->getId();

        return Session::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (Session $session): array => [
                'id' => (string) $session->getKey(),
                'device' => $session->getDeviceName(),
                'ip' => $session->ip_address,
                'last_active' => $session->last_active->toIso8601String(),
                'current' => (string) $session->getKey() === $current,
            ])
            ->values()
            ->all();
    }
}
