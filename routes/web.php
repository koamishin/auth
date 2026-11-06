<?php

use App\Enums\SocialLoginProvider;
use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Http\Controllers\Auth\EmailCodeSignInController;
use App\Http\Controllers\Auth\HostedAppearancePreviewController;
use App\Http\Controllers\Auth\HostedAuthenticationController;
use App\Http\Controllers\Auth\OpenIdConnectController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\VerifyEmailCodeController;
use App\Http\Controllers\ImpersonateController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Setup\SetupController;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Settings\ApplicationFeaturesSettings;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', fn () => Inertia::render('Welcome', [
    'canRegister' => Features::enabled(Features::registration()) && app(ApplicationFeaturesSettings::class)->registration_enabled,
]))->name('home');

Route::get('dashboard', function (Request $request) {
    $user = $request->user();
    if ($user instanceof User && $user->canAccessPanel(Filament::getPanel('admin'))) {
        return $request->header('X-Inertia')
            ? Inertia::location(url('/admin'))
            : redirect()->to('/admin');
    }

    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
});

Route::middleware('web')->group(function (): void {
    /** @phpstan-ignore-next-line */
    Route::impersonate();
    Route::get('impersonate/take-redirect', [ImpersonateController::class, 'takeRedirect'])->name('impersonate.take-redirect');
    Route::get('impersonate/leave-redirect', [ImpersonateController::class, 'leaveRedirect'])->name('impersonate.leave-redirect');
});

$socialProviders = implode('|', SocialLoginProvider::values());

Route::match(['get', 'post'], 'auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->where('provider', $socialProviders)
    ->name('auth.social.redirect');

Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->where('provider', $socialProviders)
    ->name('auth.social.callback');

Route::get('auth/hosted', [HostedAuthenticationController::class, 'restore'])->name('hosted.restore');
Route::post('auth/hosted', HostedAuthenticationController::class)->name('hosted.store');

// Passwordless sign-in with a one-time email code.
Route::post('auth/email-code', [EmailCodeSignInController::class, 'send'])
    ->middleware('throttle:6,1')
    ->name('auth.email-code.send');
Route::post('auth/email-code/verify', [EmailCodeSignInController::class, 'verify'])
    ->middleware('throttle:10,1')
    ->name('auth.email-code.verify');

// Email verification by one-time code.
Route::post('email/verify-code', [VerifyEmailCodeController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('verification.code.store');
Route::post('email/verify-code/resend', [VerifyEmailCodeController::class, 'resend'])
    ->middleware(['auth', 'throttle:3,1'])
    ->name('verification.code.resend');

Route::get('.well-known/openid-configuration', [OpenIdConnectController::class, 'discovery'])->name('openid.discovery');
Route::get('oauth/jwks', [OpenIdConnectController::class, 'jwks'])->name('oauth.jwks');

Route::get('setup', [SetupController::class, 'index'])->name('setup.index');
Route::post('setup', [SetupController::class, 'store'])->name('setup.store');

Route::middleware(['web', 'auth'])->get('admin/{tenant:slug}/tenant-applications/{application}/appearance/preview', HostedAppearancePreviewController::class)
    ->name('tenant-applications.appearance.preview');

Route::middleware(['web', 'auth'])->post('admin/{tenant:slug}/switch-app/{application}', function (Request $request, Tenant $tenant, TenantApplication $application): RedirectResponse {
    $user = $request->user();
    abort_unless($user instanceof User && $user->canAccessPanel(Filament::getPanel('admin')) && $user->canAccessTenant($tenant), 403);
    abort_unless($application->tenant_id === $tenant->id, 404);

    session(['current_application_id' => $application->id]);

    return redirect()->to(TenantApplicationResource::getUrl('view', [
        'record' => $application,
        'tenant' => $tenant,
    ]));
})->name('filament.admin.switch-application');

Route::middleware(['web', 'auth'])->post('admin/{tenant:slug}/toggle-env/{application}/{environment}', function (Request $request, Tenant $tenant, TenantApplication $application, string $environment): RedirectResponse {
    $user = $request->user();
    abort_unless($user instanceof User && $user->canAccessPanel(Filament::getPanel('admin')) && $user->canAccessTenant($tenant), 403);
    abort_unless($application->tenant_id === $tenant->id, 404);

    if (! in_array($environment, ['development', 'production'], true)) {
        abort(400);
    }

    $configuration = $application->environments()->where('environment', $environment)->first();
    abort_unless($configuration !== null, 409, 'The selected environment is not configured.');

    $application->update([
        'environment' => $environment,
        'oauth_client_id' => $configuration->oauth_client_id,
        'redirect_uris' => $configuration->redirect_uris,
    ]);

    // Keep the user on the page they were configuring when switching
    // environments; fall back to the application overview otherwise.
    $redirect = $request->input('redirect');
    $fallback = TenantApplicationResource::getUrl('view', [
        'record' => $application,
        'tenant' => $tenant,
    ]);

    if (! is_string($redirect) || $redirect === '' || ! str_starts_with($redirect, url('/'))) {
        $redirect = $fallback;
    }

    return redirect()->to($redirect);
})->name('filament.admin.toggle-environment');

require __DIR__.'/settings.php';
