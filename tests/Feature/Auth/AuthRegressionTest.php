<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\ApplicationInvitation;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Notifications\ApplicationInvitationNotification;
use App\Services\Auth\ApplicationAccessPolicy;
use App\Services\Auth\ApplicationInvitationService;
use App\Services\OAuth\ApplicationAuthentication;
use App\Services\OAuth\ApplicationProvisioner;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;

/**
 * @return array{0: TenantApplication, 1: TenantApplicationEnvironment}
 */
function regressionEnvironment(string $slug, array $settings = []): array
{
    $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => $slug.' app',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://'.$slug.'.internal/callback'],
    ]);

    $environment = $application->environments()->where('environment', 'development')->sole();

    if ($settings !== []) {
        $environment->update(['settings' => [...$environment->settings, ...$settings]]);
    }

    return [$application, $environment->refresh()];
}

test('a password reset obeys the application minimum without any flow in the link', function (): void {
    [, $environment] = regressionEnvironment('regression-reset-min', ['auth_password_min_length' => 16]);

    $user = User::factory()->create([
        'email' => 'reset@regression.test',
        'password' => Hash::make('Password-1234!'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    // The environment is resolved from the account being reset, not the request.
    expect(PasswordPolicy::minimum($user->tenantApplicationEnvironment))->toBe(16);

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'Short-1234!',
        'password_confirmation' => 'Short-1234!',
    ]))->toThrow(ValidationException::class);

    expect(Hash::check('Password-1234!', $user->refresh()->password))->toBeTrue();

    app(ResetUserPassword::class)->reset($user, [
        'password' => 'A-Long-Enough-Password-1!',
        'password_confirmation' => 'A-Long-Enough-Password-1!',
    ]);

    expect(Hash::check('A-Long-Enough-Password-1!', $user->refresh()->password))->toBeTrue();
});

test('a password reset is refused when the application disables passwords', function (): void {
    [, $environment] = regressionEnvironment('regression-reset-disabled', ['auth_password_enabled' => false]);

    $user = User::factory()->create([
        'email' => 'nopass@regression.test',
        'password' => Hash::make('Password-1234!'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'A-Long-Enough-Password-1!',
        'password_confirmation' => 'A-Long-Enough-Password-1!',
    ]))->toThrow(ValidationException::class);

    expect(Hash::check('Password-1234!', $user->refresh()->password))->toBeTrue();
});

test('a portal account is unaffected by application password rules', function (): void {
    $portalUser = User::factory()->create([
        'email' => 'portal@regression.test',
        'password' => Hash::make('Password-1234!'),
        'identity_namespace' => User::PORTAL_IDENTITY_NAMESPACE,
        'tenant_application_environment_id' => null,
    ]);

    expect(PasswordPolicy::minimum($portalUser->tenantApplicationEnvironment))->toBe(PasswordPolicy::FALLBACK_MINIMUM);

    app(ResetUserPassword::class)->reset($portalUser, [
        'password' => 'Short-1234!',
        'password_confirmation' => 'Short-1234!',
    ]);

    expect(Hash::check('Short-1234!', $portalUser->refresh()->password))->toBeTrue();
});

test('passkey multi-factor satisfaction belongs to one sign-in and is cleared by a password sign-in', function (): void {
    [, $environment] = regressionEnvironment('regression-mfa-flag', ['require_mfa' => true]);

    $user = User::factory()->create([
        'email' => 'flag@regression.test',
        'password' => Hash::make('member-password'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $authentication = app(ApplicationAuthentication::class);

    // A passkey sign-in records satisfaction.
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));

    $authentication->markSignInSatisfiesMultiFactor($request, true);
    expect($request->session()->get(ApplicationAuthentication::MFA_SATISFIED_BY_PASSKEY))->toBeTrue();

    // Any other sign-in clears it, so it cannot be inherited later in the session.
    $authentication->markSignInSatisfiesMultiFactor($request, false);
    expect($request->session()->get(ApplicationAuthentication::MFA_SATISFIED_BY_PASSKEY))->toBeNull();

    expect($user->hasEnrolledSecondFactor())->toBeFalse();
});

test('the multi-factor setup screen keeps its application context', function (): void {
    [$application, $environment] = regressionEnvironment('regression-mfa-context', ['require_mfa' => true]);

    $user = User::factory()->create([
        'email' => 'setup@regression.test',
        'password' => Hash::make('member-password'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $client = new Client;
    $client->forceFill([
        'name' => 'regression-mfa-context',
        'secret' => 'secret',
        'redirect_uris' => ['https://regression-mfa-context.internal/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ])->save();

    $environment->update(['oauth_client_id' => $client->getKey()]);
    $application->update(['oauth_client_id' => $client->getKey()]);

    $this->actingAs($user);

    $verifier = str_repeat('f', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $start = $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://regression-mfa-context.internal/callback',
        'response_type' => 'code',
        'state' => 'mfa',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    $flow = $start->viewData('flow');

    // The standalone setup URL carries the flow, so the page knows its application.
    $props = $this->get('/mfa/setup?flow='.$flow)->assertOk()->viewData('page')['props'];

    expect($props['application']['name'])->toBe('regression-mfa-context app');
    expect($props['flow'])->toBe($flow);
    expect($props['continueUrl'])->toContain('oauth/authorize');
});

test('an invitation cannot override a block rule', function (): void {
    Notification::fake();
    [$application, $environment] = regressionEnvironment('regression-invite-block', [
        'access_mode' => 'invite_only',
        'access_rules' => [['type' => 'block', 'kind' => 'domain', 'value' => 'blocked.example']],
    ]);

    app(ApplicationInvitationService::class)->invite($environment, 'person@blocked.example');

    $token = null;
    Notification::assertSentOnDemand(ApplicationInvitationNotification::class, function (ApplicationInvitationNotification $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $user = User::factory()->create([
        'email' => 'person@blocked.example',
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $this->actingAs($user)
        ->post(route('invitations.accept', $token))
        ->assertSessionHasErrors('invitation');

    expect($application->users('development')->whereKey($user->getKey())->exists())->toBeFalse();
    expect(ApplicationInvitation::query()->sole()->status()->value)->toBe('pending');
});

test('an invitation may bypass a narrowing allowlist', function (): void {
    Notification::fake();
    [$application, $environment] = regressionEnvironment('regression-invite-allow', [
        'access_mode' => 'invite_only',
        'access_rules' => [['type' => 'allow', 'kind' => 'domain', 'value' => 'partner.example']],
    ]);

    app(ApplicationInvitationService::class)->invite($environment, 'guest@other.example');

    $token = null;
    Notification::assertSentOnDemand(ApplicationInvitationNotification::class, function (ApplicationInvitationNotification $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $user = User::factory()->create([
        'email' => 'guest@other.example',
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    // The allowlist would refuse this address, but the invitation is an explicit
    // grant, so it is accepted.
    expect(app(ApplicationAccessPolicy::class)->allows($environment, 'guest@other.example'))->toBeFalse();

    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect();

    expect($application->users('development')->whereKey($user->getKey())->exists())->toBeTrue();
});

test('disposable and subaddressed invitations are refused when the application protects them', function (): void {
    Notification::fake();
    [, $environment] = regressionEnvironment('regression-invite-protections', [
        'access_mode' => 'invite_only',
        'block_disposable_emails' => true,
        'block_email_subaddresses' => true,
    ]);

    $policy = app(ApplicationAccessPolicy::class);

    expect($policy->isBlockedAddress($environment, 'person@mailinator.com'))->toBeTrue();
    expect($policy->isBlockedAddress($environment, 'person+tag@example.com'))->toBeTrue();
    expect($policy->isBlockedAddress($environment, 'person@example.com'))->toBeFalse();
});
