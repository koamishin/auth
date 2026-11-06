<?php

use App\Enums\EmailCodePurpose;
use App\Models\EmailVerificationCode;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Services\Auth\EmailCodeService;
use App\Services\OAuth\ApplicationProvisioner;
use App\Support\VerificationDevice;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;

/**
 * @return array{0: TenantApplication, 1: TenantApplicationEnvironment}
 */
function emailCodeEnvironment(string $slug, array $settings = []): array
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

function emailCodeUser(TenantApplicationEnvironment $environment, array $attributes = []): User
{
    return User::factory()->create([
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
        ...$attributes,
    ]);
}

/**
 * Read the code that was emailed, so a test can submit it.
 */
function latestEmailedCode(User $user): string
{
    $code = null;

    Notification::assertSentTo($user, EmailVerificationCodeNotification::class, function (EmailVerificationCodeNotification $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    if (! is_string($code)) {
        throw new LogicException('No verification code was sent.');
    }

    return $code;
}

/**
 * Bind the application environment to the current request, the way the OAuth
 * middleware does while serving an application flow.
 */
function emailCodeBindEnvironment(?TenantApplicationEnvironment $environment): void
{
    request()->attributes->set('koamishin.application_environment', $environment);
}

/**
 * Start a real application authorization flow so the email code endpoints see an
 * application context, exactly as they do in production.
 *
 * @param  array<string, mixed>  $settings
 * @return array{0: Client, 1: TenantApplication, 2: TenantApplicationEnvironment, 3: string}
 */
function emailCodeSignInEnvironment(string $slug, array $settings): array
{
    $client = new Client;
    $client->forceFill([
        'name' => $slug,
        'secret' => 'email-code-secret',
        'redirect_uris' => ['https://'.$slug.'.internal/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ])->save();

    $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);
    $application = $tenant->applications()->create([
        'name' => $slug,
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => $client->getAttribute('redirect_uris'),
        'oauth_client_id' => $client->getKey(),
    ]);

    $environment = $application->environments()->create([
        'environment' => 'development',
        'oauth_client_id' => $client->getKey(),
        'redirect_uris' => $client->getAttribute('redirect_uris'),
        'settings' => ['allow_signups' => true, ...$settings],
    ]);

    $email = $slug.'@email-code.test';

    return [$client, $application, $environment, $email];
}

/**
 * Obtain a flow identifier by starting the authorization request.
 */
function emailCodeFlowId(Client $client, string $slug): string
{
    $verifier = str_repeat('c', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $start = test()->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://'.$slug.'.internal/callback',
        'response_type' => 'code',
        'state' => 'email-code',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    $flow = $start->viewData('flow');

    if (! is_string($flow) || $flow === '') {
        throw new LogicException('The authorization request did not return a flow.');
    }

    return $flow;
}

test('a verification code is hashed, single use, and expires', function (): void {
    Notification::fake();
    [, $environment] = emailCodeEnvironment('code-basics');
    $user = emailCodeUser($environment);

    $service = app(EmailCodeService::class);
    $service->send($user, EmailCodePurpose::Verification);

    $record = EmailVerificationCode::query()->where('user_id', $user->getKey())->sole();

    // The plain code is never stored.
    expect($record->code_hash)->not->toBe(latestEmailedCode($user));
    expect($record->code_hash)->toStartWith('$2y$');

    // It is only valid once.
    $service->verify($user, latestEmailedCode($user), EmailCodePurpose::Verification);
    expect($record->refresh()->consumed_at)->not->toBeNull();

    expect(fn () => $service->verify($user, latestEmailedCode($user), EmailCodePurpose::Verification))
        ->toThrow(ValidationException::class);
});

test('an expired code is refused', function (): void {
    Notification::fake();
    [, $environment] = emailCodeEnvironment('code-expiry');
    $user = emailCodeUser($environment);

    $service = app(EmailCodeService::class);
    $service->send($user, EmailCodePurpose::Verification);

    $code = latestEmailedCode($user);

    EmailVerificationCode::query()->where('user_id', $user->getKey())->update([
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => $service->verify($user, $code, EmailCodePurpose::Verification))
        ->toThrow(ValidationException::class);
});

test('a code stops accepting guesses after the attempt limit', function (): void {
    Notification::fake();
    [, $environment] = emailCodeEnvironment('code-attempts');
    $user = emailCodeUser($environment);

    $service = app(EmailCodeService::class);
    $service->send($user, EmailCodePurpose::Verification);
    $code = latestEmailedCode($user);

    for ($attempt = 1; $attempt < EmailCodeService::MAX_ATTEMPTS; $attempt++) {
        expect(fn () => $service->verify($user, '000000', EmailCodePurpose::Verification))
            ->toThrow(ValidationException::class);
    }

    // The final wrong guess burns the code, so even the right code is refused.
    expect(fn () => $service->verify($user, '000000', EmailCodePurpose::Verification))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->verify($user, $code, EmailCodePurpose::Verification))
        ->toThrow(ValidationException::class);
});

test('requesting another code is rate limited by a cooldown', function (): void {
    Notification::fake();
    [, $environment] = emailCodeEnvironment('code-cooldown');
    $user = emailCodeUser($environment);

    $service = app(EmailCodeService::class);
    $service->send($user, EmailCodePurpose::Verification);

    expect($service->cooldownRemaining($user, EmailCodePurpose::Verification))->toBeGreaterThan(0);

    expect(fn () => $service->send($user, EmailCodePurpose::Verification))
        ->toThrow(ValidationException::class);

    // Once the cooldown has passed a new code can be issued, and it replaces the old one.
    $this->travel(EmailCodeService::RESEND_COOLDOWN_SECONDS + 1)->seconds();

    $service->send($user, EmailCodePurpose::Verification);

    expect(EmailVerificationCode::query()->where('user_id', $user->getKey())->whereNull('consumed_at')->count())->toBe(1);
});

test('a verification code completes email verification for an application', function (): void {
    Notification::fake();
    [$application, $environment] = emailCodeEnvironment('code-verify', [
        'email_verification_required' => true,
        'email_verification_method' => 'code',
    ]);

    $user = emailCodeUser($environment, ['email_verified_at' => null]);
    $application->users('development')->attach($user, ['role' => 'member', 'environment' => 'development']);

    // Requesting verification sends a code rather than a link.
    emailCodeBindEnvironment($environment);
    $user->sendEmailVerificationNotification();
    expect(EmailVerificationCode::query()->where('user_id', $user->getKey())->exists())->toBeTrue();

    $code = latestEmailedCode($user);

    $this->actingAs($user)
        ->post(route('verification.code.store'), ['code' => $code])
        ->assertRedirect();

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('an incorrect verification code leaves the address unverified', function (): void {
    Notification::fake();
    [, $environment] = emailCodeEnvironment('code-wrong', [
        'email_verification_required' => true,
        'email_verification_method' => 'code',
    ]);

    $user = emailCodeUser($environment, ['email_verified_at' => null]);
    emailCodeBindEnvironment($environment);
    $user->sendEmailVerificationNotification();

    $this->actingAs($user)
        ->post(route('verification.code.store'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

test('an application using links still sends a link rather than a code', function (): void {
    Notification::fake();
    [, $environment] = emailCodeEnvironment('code-links', [
        'email_verification_required' => true,
        'email_verification_method' => 'link',
    ]);

    $user = emailCodeUser($environment, ['email_verified_at' => null]);
    emailCodeBindEnvironment($environment);
    $user->sendEmailVerificationNotification();

    Notification::assertSentTo($user, VerifyEmail::class);
    expect(EmailVerificationCode::query()->where('user_id', $user->getKey())->exists())->toBeFalse();
});

test('signing in with an email code works when the application allows it', function (): void {
    Notification::fake();
    [$client, $application, $environment, $email] = emailCodeSignInEnvironment('code-signin', [
        'auth_email_code_signin' => true,
    ]);

    $user = emailCodeUser($environment, ['email' => $email]);
    $application->users('development')->attach($user, ['role' => 'member', 'environment' => 'development']);

    $flow = emailCodeFlowId($client, 'code-signin');

    $this->post(route('auth.email-code.send'), ['email' => $email, 'flow' => $flow])->assertRedirect();

    $code = latestEmailedCode($user);

    $this->post(route('auth.email-code.verify'), [
        'email' => $email,
        'code' => $code,
        'flow' => $flow,
    ])->assertRedirect();

    $this->assertAuthenticated();
});

test('signing in with an email code is refused when the application disables it', function (): void {
    Notification::fake();
    [$client, $application, $environment, $email] = emailCodeSignInEnvironment('code-signin-off', []);

    $user = emailCodeUser($environment, ['email' => $email]);
    $application->users('development')->attach($user, ['role' => 'member', 'environment' => 'development']);

    $this->post(route('auth.email-code.send'), [
        'email' => $email,
        'flow' => emailCodeFlowId($client, 'code-signin-off'),
    ])->assertSessionHasErrors('email');

    expect(EmailVerificationCode::query()->where('user_id', $user->getKey())->exists())->toBeFalse();
    $this->assertGuest();
});

test('signing in with an email code is unavailable outside an application', function (): void {
    Notification::fake();

    $this->post(route('auth.email-code.send'), ['email' => 'portal@email-code.test'])
        ->assertSessionHasErrors('email');

    Notification::assertNothingSent();
    $this->assertGuest();
});

test('requesting a sign-in code never reveals whether an account exists', function (): void {
    Notification::fake();
    [$client] = emailCodeSignInEnvironment('code-enumeration', ['auth_email_code_signin' => true]);

    $this->post(route('auth.email-code.send'), [
        'email' => 'nobody@code-enumeration.test',
        'flow' => emailCodeFlowId($client, 'code-enumeration'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

test('a sign-in code cannot be used for an account in another environment', function (): void {
    Notification::fake();
    [$clientA] = emailCodeSignInEnvironment('code-scope-a', ['auth_email_code_signin' => true]);
    [, , $environmentB] = emailCodeSignInEnvironment('code-scope-b', ['auth_email_code_signin' => true]);

    // The same address exists in environment B, and a code is issued for it.
    $userB = emailCodeUser($environmentB, ['email' => 'shared@code-scope.test']);
    app(EmailCodeService::class)->send($userB, EmailCodePurpose::SignIn);
    $code = latestEmailedCode($userB);

    // Presenting that code inside environment A must not sign anyone in.
    $this->post(route('auth.email-code.verify'), [
        'email' => 'shared@code-scope.test',
        'code' => $code,
        'flow' => emailCodeFlowId($clientA, 'code-scope-a'),
    ])->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('verification links are bound to the requesting device when enabled', function (): void {
    Notification::fake();
    [, $environment] = emailCodeEnvironment('code-device-binding', [
        'email_verification_required' => true,
        'email_verification_method' => 'link',
        'email_verification_bind_device' => true,
    ]);

    expect($environment->bindsVerificationLinksToDevice())->toBeTrue();

    $device = app(VerificationDevice::class);
    $request = Request::create('/register', 'POST');

    // A link generated for one device does not match another.
    $fingerprint = $device->fingerprint($request, create: true);
    expect($fingerprint)->not->toBeNull();

    $otherDevice = Request::create('/verify', 'GET');
    expect($device->matches($otherDevice, $fingerprint))->toBeFalse();

    // The same device matches, because the token lives in its cookie.
    $sameDevice = Request::create('/verify', 'GET', [], [$device::COOKIE => $request->cookies->get($device::COOKIE)]);
    expect($device->matches($sameDevice, $fingerprint))->toBeTrue();

    // Links without a fingerprint (portal, or issued before this was enabled) pass.
    expect($device->matches($otherDevice, null))->toBeTrue();
});

test('device binding can be turned off per application', function (): void {
    [, $environment] = emailCodeEnvironment('code-device-off', [
        'email_verification_bind_device' => false,
    ]);

    expect($environment->bindsVerificationLinksToDevice())->toBeFalse();
});

test('the verification link lifetime is configurable per application', function (): void {
    [, $environment] = emailCodeEnvironment('code-ttl', [
        'email_verification_link_ttl' => 30,
    ]);

    expect($environment->emailVerificationLinkTtl())->toBe(30);

    // Out of range values fall back to the platform default.
    $environment->update(['settings' => [...$environment->settings, 'email_verification_link_ttl' => 9000]]);
    expect($environment->refresh()->emailVerificationLinkTtl())
        ->toBe(TenantApplicationEnvironment::DEFAULT_VERIFICATION_LINK_TTL);

    [, $defaults] = emailCodeEnvironment('code-ttl-default');
    expect($defaults->emailVerificationLinkTtl())
        ->toBe(TenantApplicationEnvironment::DEFAULT_VERIFICATION_LINK_TTL)
        ->toBe(10);
});

test('a verification code cannot be reused after the address is verified', function (): void {
    Notification::fake();
    [, $environment] = emailCodeEnvironment('code-reuse', [
        'email_verification_required' => true,
        'email_verification_method' => 'code',
    ]);

    $user = emailCodeUser($environment, ['email_verified_at' => null]);
    emailCodeBindEnvironment($environment);
    $user->sendEmailVerificationNotification();
    $code = latestEmailedCode($user);

    $this->actingAs($user)->post(route('verification.code.store'), ['code' => $code])->assertRedirect();
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();

    // A second submission of the same code is rejected rather than silently accepted.
    $this->actingAs($user)->post(route('verification.code.store'), ['code' => $code])->assertRedirect();

    expect(Hash::check($code, EmailVerificationCode::query()->where('user_id', $user->getKey())->sole()->code_hash))->toBeTrue();
});
