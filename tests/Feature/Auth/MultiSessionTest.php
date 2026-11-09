<?php

use App\Http\Middleware\EnforceSessionLifetime;
use App\Models\MultiSessionAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\MultiSession;
use App\Settings\ApplicationFeaturesSettings;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Sign in through the real login screen, sending the browser's registry cookie
 * along, so the registry is built up exactly the way a browser builds it up:
 * every sign-in adds to the accounts already held.
 */
function multiSessionSignIn(User $user, ?string $registry = null, bool $addAccount = false): TestResponse
{
    if ($registry !== null) {
        test()->withCookie(MultiSession::COOKIE, $registry);
    }

    return test()->post(route('login.store', $addAccount ? ['add' => 1] : []), [
        'email' => $user->email,
        'password' => 'password',
    ]);
}

/**
 * The decrypted account-registry cookie a response set, ready to be sent back
 * the way a browser would send it.
 */
function multiSessionCookie(TestResponse $response): string
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === MultiSession::COOKIE) {
            return CookieValuePrefix::remove(decrypt($cookie->getValue(), false));
        }
    }

    throw new RuntimeException('The response set no '.MultiSession::COOKIE.' cookie.');
}

/**
 * The account ids held in a registry cookie.
 *
 * @return array<int, int>
 */
function multiSessionIds(string $registry): array
{
    return array_map(
        fn (array $entry): int => (int) $entry['id'],
        json_decode($registry, true) ?? [],
    );
}

test('a completed sign-in registers the account in the browser registry', function (): void {
    $user = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($user)->assertRedirect(route('dashboard', absolute: false)));

    expect(MultiSessionAccount::query()->where('user_id', $user->getKey())->count())->toBe(1);
    expect(multiSessionIds($registry))->toBe([(int) $user->getKey()]);
});

test('a browser can hold several signed-in accounts at once', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($first));
    $registry = multiSessionCookie(multiSessionSignIn($second, $registry, addAccount: true));

    $this->assertAuthenticatedAs($second);
    expect(multiSessionIds($registry))->toBe([(int) $first->getKey(), (int) $second->getKey()]);

    // Both are offered as the signed-in accounts of this browser.
    $this->actingAs($second)
        ->withCookie(MultiSession::COOKIE, $registry)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $assert): AssertableInertia => $assert
            ->has('auth.accounts', 2)
            ->where('auth.accounts.0.id', (int) $first->getKey())
            ->where('auth.accounts.0.current', false)
            ->where('auth.accounts.1.id', (int) $second->getKey())
            ->where('auth.accounts.1.current', true)
        );
});

test('switching signs the browser in as another held account without a password', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($first));
    $registry = multiSessionCookie(multiSessionSignIn($second, $registry, addAccount: true));

    $this->withCookie(MultiSession::COOKIE, $registry)
        ->post(route('accounts.switch'), ['user_id' => $first->getKey()])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($first);

    // Both accounts stay switchable after the switch.
    expect(MultiSessionAccount::query()->count())->toBe(2);
});

test('switching clears the previous account state but keeps the pinned session lifetime', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($first));
    $registry = multiSessionCookie(multiSessionSignIn($second, $registry, addAccount: true));

    $pinned = ['idle' => 30, 'max' => 60, 'started_at' => now()->timestamp, 'last_seen_at' => now()->timestamp];

    $this->withCookie(MultiSession::COOKIE, $registry)
        ->withSession([
            EnforceSessionLifetime::SESSION_KEY => $pinned,
            'url.intended' => 'https://somewhere.example/secret',
        ])
        ->post(route('accounts.switch'), ['user_id' => $first->getKey()])
        ->assertSessionHas(EnforceSessionLifetime::SESSION_KEY, $pinned)
        ->assertSessionMissing('url.intended');
});

test('switching is refused for an account this browser holds no token for', function (): void {
    $held = User::factory()->create();
    $stranger = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($held));

    $this->withCookie(MultiSession::COOKIE, $registry)
        ->post(route('accounts.switch'), ['user_id' => $stranger->getKey()])
        ->assertSessionHasErrors('account');

    $this->assertAuthenticatedAs($held);
});

test('revoking a users sessions revokes their switch tokens too', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($first));
    $registry = multiSessionCookie(multiSessionSignIn($second, $registry, addAccount: true));

    $first->revokeAllSessions();

    expect(MultiSessionAccount::query()->where('user_id', $first->getKey())->count())->toBe(0);

    $this->withCookie(MultiSession::COOKIE, $registry)
        ->post(route('accounts.switch'), ['user_id' => $first->getKey()])
        ->assertSessionHasErrors('account');

    $this->assertAuthenticatedAs($second);
});

test('banned accounts cannot be switched into', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($first));
    $registry = multiSessionCookie(multiSessionSignIn($second, $registry, addAccount: true));

    $first->ban();

    $this->withCookie(MultiSession::COOKIE, $registry)
        ->post(route('accounts.switch'), ['user_id' => $first->getKey()])
        ->assertSessionHasErrors('account');

    $this->assertAuthenticatedAs($second);
});

test('signing out removes only the current account from the registry', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($first));
    $registry = multiSessionCookie(multiSessionSignIn($second, $registry, addAccount: true));

    $response = $this->withCookie(MultiSession::COOKIE, $registry)->post(route('logout'));

    $this->assertGuest();
    expect(MultiSessionAccount::query()->where('user_id', $second->getKey())->count())->toBe(0);

    // The other account stays signed in and is offered on the login page.
    $this->withCookie(MultiSession::COOKIE, multiSessionCookie($response))
        ->get(route('login'))
        ->assertInertia(fn (Assert $assert): AssertableInertia => $assert
            ->has('accounts', 1)
            ->where('accounts.0.email', $first->email)
        );
});

test('removing the current account lands on the login page with the rest offered', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($first));
    $registry = multiSessionCookie(multiSessionSignIn($second, $registry, addAccount: true));

    $response = $this->withCookie(MultiSession::COOKIE, $registry)
        ->post(route('accounts.remove'), ['user_id' => $second->getKey()])
        ->assertRedirect(route('login'));

    $this->assertGuest();

    // The remaining account is offered to continue as, without a password.
    $this->withCookie(MultiSession::COOKIE, multiSessionCookie($response))
        ->get(route('login'))
        ->assertInertia(fn (Assert $assert): AssertableInertia => $assert
            ->has('accounts', 1)
            ->where('accounts.0.email', $first->email)
            ->where('accounts.0.current', false)
            ->where('addAccount', false)
        );
});

test('removing another account keeps the current session', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($first));
    $registry = multiSessionCookie(multiSessionSignIn($second, $registry, addAccount: true));

    $this->withCookie(MultiSession::COOKIE, $registry)
        ->post(route('accounts.remove'), ['user_id' => $first->getKey()])
        ->assertRedirect();

    $this->assertAuthenticatedAs($second);
    expect(MultiSessionAccount::query()->where('user_id', $first->getKey())->count())->toBe(0);
    expect(MultiSessionAccount::query()->where('user_id', $second->getKey())->count())->toBe(1);
});

test('signing out of all accounts clears the registry and the session', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($first));
    $registry = multiSessionCookie(multiSessionSignIn($second, $registry, addAccount: true));

    $this->withCookie(MultiSession::COOKIE, $registry)
        ->post(route('accounts.flush'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect(MultiSessionAccount::query()->count())->toBe(0);
});

test('signing in for another account is possible while authenticated', function (): void {
    $user = User::factory()->create();

    multiSessionSignIn($user);

    // The guest middleware keeps the sign-in form away from signed-in users,
    // except when they explicitly ask to add an account.
    $this->get(route('login'))->assertRedirect();
    $this->get(route('login', ['add' => 1]))->assertOk();
});

test('the account switcher appears in the admin panel', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $tenant = Tenant::query()->create(['name' => 'Switcher', 'slug' => 'switcher', 'is_active' => true]);
    $registry = multiSessionCookie(multiSessionSignIn($admin));

    $this->actingAs($admin)
        ->withCookie(MultiSession::COOKIE, $registry)
        ->get(route('filament.admin.resources.users.index', ['tenant' => $tenant]))
        ->assertOk()
        ->assertSee(route('accounts.switch'))
        ->assertSee(route('accounts.flush'));
});

test('impersonated users never become switchable from the impersonators browser', function (): void {
    $settings = app(ApplicationFeaturesSettings::class);
    $settings->user_impersonation_enabled = true;
    app()->instance(ApplicationFeaturesSettings::class, $settings);

    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
    $target = User::factory()->create();

    $registry = multiSessionCookie(multiSessionSignIn($admin));

    $this->withCookie(MultiSession::COOKIE, $registry)
        ->get(route('impersonate', ['id' => $target->getKey()]))
        ->assertRedirect();

    // The registry still holds exactly the administrator: the impersonated
    // user never signed in on this browser.
    expect(multiSessionIds($registry))->toBe([(int) $admin->getKey()]);
    expect(MultiSessionAccount::query()->count())->toBe(1);
    expect(MultiSessionAccount::query()->where('user_id', $target->getKey())->count())->toBe(0);
});
