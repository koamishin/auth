<?php

use App\Filament\Resources\TenantApplications\Pages\AuthenticationTenantApplication;
use App\Models\RecognizedDevice;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Notifications\NewDeviceSignInNotification;
use App\Services\OAuth\ApplicationProvisioner;
use App\Support\RecognizedDevices;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * @return array{0: TenantApplication, 1: TenantApplicationEnvironment}
 */
function signinAlertEnvironment(string $slug, array $settings = []): array
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

function signinAlertFlow(TenantApplication $application): string
{
    $challenge = rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '=');
    $response = test()->get('/oauth/authorize?'.http_build_query([
        'client_id' => $application->oauth_client_id,
        'redirect_uri' => $application->redirect_uris[0],
        'response_type' => 'code',
        'state' => 'alert1234',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    $flow = $response->viewData('flow');
    expect($flow)->toBeString()->not->toBe('');

    return $flow;
}

/**
 * The revocation link sent in an alert to this user.
 */
function signinAlertUrl(User $user): string
{
    $url = '';
    Notification::assertSentTo($user, NewDeviceSignInNotification::class, function (NewDeviceSignInNotification $notification) use (&$url): bool {
        $url = $notification->revocationUrl;

        return true;
    });

    return $url;
}

test('a sign-in from an unrecognized device alerts the account owner', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    Notification::assertSentTo($user, NewDeviceSignInNotification::class, fn (NewDeviceSignInNotification $notification): bool => $notification->deviceName !== ''
        && $notification->ipAddress !== null
        && str_contains($notification->revocationUrl, 'signin-alert/'.$user->getKey().'/'));
    expect(RecognizedDevice::query()->where('user_id', $user->getKey())->count())->toBe(1);
});

test('a sign-in from a recognized device stays quiet', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    RecognizedDevice::query()->create([
        'user_id' => $user->getKey(),
        'token_hash' => hash('sha256', 'known-device-token'),
        'user_agent' => 'Mozilla/5.0',
        'ip_address' => '127.0.0.1',
        'first_seen_at' => now()->subDay(),
        'last_seen_at' => now()->subDay(),
    ]);

    test()->withCookie(RecognizedDevices::COOKIE, 'known-device-token')
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    Notification::assertNotSentTo($user, NewDeviceSignInNotification::class);
    expect(RecognizedDevice::query()->where('user_id', $user->getKey())->count())->toBe(1);
    expect(RecognizedDevice::query()->sole()->last_seen_at->isToday())->toBeTrue();
});

test('alerts follow the application setting and carry the support address', function (): void {
    Notification::fake();
    [$application, $environment] = signinAlertEnvironment('signin-alert-off', ['unauthorized_signin_alerts' => false]);
    $user = User::factory()->create([
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);
    $flow = signinAlertFlow($application);

    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'flow' => $flow])
        ->assertSessionHasNoErrors();

    // Quiet while alerts are off — but the device is still remembered.
    Notification::assertNotSentTo($user, NewDeviceSignInNotification::class);
    expect(RecognizedDevice::query()->where('user_id', $user->getKey())->count())->toBe(1);

    $environment->update(['settings' => [
        ...$environment->settings,
        'unauthorized_signin_alerts' => true,
        'support_email' => 'help@example.test',
    ]]);

    test()->withCookie(RecognizedDevices::COOKIE, 'fresh-device')
        ->post(route('login.store', ['add' => 1]), ['email' => $user->email, 'password' => 'password', 'flow' => $flow])
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, NewDeviceSignInNotification::class, fn (NewDeviceSignInNotification $notification): bool => $notification->supportEmail === 'help@example.test');
    expect(RecognizedDevice::query()->where('user_id', $user->getKey())->count())->toBe(2);
});

test('the alert link signs out the suspicious session and confirms it', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $url = signinAlertUrl($user);

    // The link names the session created by that sign-in.
    $sessionId = (string) last(explode('/', (string) parse_url($url, PHP_URL_PATH)));
    Session::query()->create([
        'id' => $sessionId,
        'user_id' => $user->getKey(),
        'payload' => '',
        'last_activity' => time(),
    ]);

    test()->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $assert): AssertableInertia => $assert
            ->component('auth/UnauthorizedSignIn')
            ->where('revoked', true)
        );

    expect(Session::query()->whereKey($sessionId)->exists())->toBeFalse();
});

test('the alert link only ever signs out the session it names', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $other = User::factory()->create();
    Session::query()->create(['id' => 'someotherusersession', 'user_id' => $other->getKey(), 'payload' => '', 'last_activity' => time()]);

    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $url = signinAlertUrl($user);

    // Swap in another user's session: the signed link refuses to touch it.
    $sessionId = (string) last(explode('/', (string) parse_url($url, PHP_URL_PATH)));
    $tampered = str_replace('/'.$sessionId, '/someotherusersession', $url);

    test()->get($tampered)->assertStatus(403);

    expect(Session::query()->whereKey('someotherusersession')->exists())->toBeTrue();
});

test('the alert link stops working after a day', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $url = signinAlertUrl($user);

    $this->travel(25)->hours();

    test()->get($url)->assertStatus(403);
});

test('the system page configures the alerts', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    [$application, $environment] = signinAlertEnvironment('signin-alert-settings');

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    test()->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($application->tenant);
    Filament::bootCurrentPanel();

    $page = fn () => Livewire::actingAs($admin)->test(
        AuthenticationTenantApplication::class,
        ['record' => $application->getKey()],
    )->assertOk();

    $page()->assertSet('data.unauthorized_signin_alerts', true);

    $page()->set('data.unauthorized_signin_alerts', false)
        ->set('data.support_email', 'help@example.test')
        ->call('save', false)
        ->assertHasNoErrors();

    expect($environment->refresh()->alertsOnUnrecognizedDeviceSignin())->toBeFalse();
    expect($environment->supportEmail())->toBe('help@example.test');

    // Defaults are not persisted.
    $page()->set('data.unauthorized_signin_alerts', true)
        ->set('data.support_email')
        ->call('save', false)
        ->assertHasNoErrors();

    expect($environment->refresh()->getSetting('unauthorized_signin_alerts'))->toBeNull();
    expect($environment->refresh()->supportEmail())->toBeNull();
});
