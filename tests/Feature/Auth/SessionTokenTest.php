<?php

use App\Enums\SessionTask;
use App\Models\Tenant;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Auth\MultiSession;
use App\Services\Auth\SessionTokenService;
use App\Services\OAuth\ApplicationAuthentication;
use App\Support\FactorVerifications;
use DateTimeImmutable;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;

/**
 * A client and application environment, the way an integration holds them.
 *
 * @return array{0: Client, 1: TenantApplicationEnvironment}
 */
function sessionTokenEnvironment(string $slug, array $settings = []): array
{
    // A deterministic consent screen, so the code exchange has one.
    if (! array_key_exists('oauth_consent_enabled', $settings)) {
        $settings['oauth_consent_enabled'] = true;
    }

    $client = new Client;
    $client->forceFill([
        'name' => $slug,
        'secret' => $slug.'-secret',
        'redirect_uris' => ['https://'.$slug.'.internal/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ])->save();
    $client->plainSecret = $slug.'-secret';

    $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
    $application = $tenant->applications()->create([
        'name' => $slug.' app',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => $client->getAttribute('redirect_uris'),
        'oauth_client_id' => $client->getKey(),
    ]);
    $environment = $application->environments()->create([
        'environment' => 'development',
        'oauth_client_id' => $client->getKey(),
        'redirect_uris' => $client->getAttribute('redirect_uris'),
        'settings' => $settings,
    ]);

    return [$client, $environment->refresh()];
}

/**
 * Enroll a user in the environment's organization and application.
 */
function sessionTokenEnroll(User $user, TenantApplicationEnvironment $environment, string $role = 'member'): void
{
    $application = $environment->application;
    $application->tenant->users()->attach($user);
    $application->users($environment->environment)->attach($user, [
        'environment' => $environment->environment,
        'role' => $role,
    ]);
}

/**
 * A user attached to an application environment.
 *
 * @param  array<string, mixed>  $attributes
 */
function sessionTokenUser(TenantApplicationEnvironment $environment, array $attributes = []): User
{
    return User::factory()->create([
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
        ...$attributes,
    ]);
}

/**
 * The decrypted value of a cookie a response set, ready to be sent back the
 * way a browser would send it.
 */
function sessionTokenCookie(TestResponse $response, string $name): string
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return CookieValuePrefix::remove(decrypt($cookie->getValue(), false));
        }
    }

    throw new RuntimeException('The response set no '.$name.' cookie.');
}

/**
 * Parse a minted token, verify its signature against the portal's own key,
 * and return its claims.
 *
 * @return array<string, mixed>
 */
function sessionTokenClaims(TestResponse $response): array
{
    $payload = $response->assertOk()->json();
    expect($payload['token_type'] ?? null)->toBe('Bearer');
    expect($payload['expires_in'] ?? null)->toBe(SessionTokenService::LIFETIME_SECONDS);

    $token = (new Parser(new JoseEncoder))->parse((string) $payload['token']);
    expect($token)->toBeInstanceOf(UnencryptedToken::class);

    (new Validator)->assert($token, new SignedWith(new Sha256, InMemory::file(storage_path('oauth-public.key'))));

    /** @var UnencryptedToken $token */
    $claims = array_map(static fn ($claim): mixed => is_object($claim) && method_exists($claim, 'getValue') ? $claim->getValue() : $claim, $token->claims()->all());
    $claims['__expires_at'] = $payload['expires_at'];

    return $claims;
}

/**
 * A real authorization-code exchange, the way a client application gets its
 * first access token for a user.
 */
function sessionTokenCodeGrant(Client $client, User $user): string
{
    $challenge = rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '=');
    $authorize = '/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $client->getAttribute('redirect_uris')[0],
        'response_type' => 'code',
        'state' => 'session-token',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);

    $start = test()->get($authorize)->assertOk();
    $flow = $start->viewData('flow');
    expect($flow)->toBeString()->not->toBe('');

    $login = test()->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'flow' => $flow,
    ]);
    $login->assertRedirect();

    $consent = test()->get((string) $login->headers->get('Location'))->assertOk();
    $page = $consent->viewData('page');
    $authToken = is_array($page) ? ($page['props']['authToken'] ?? null) : null;
    expect($authToken)->toBeString();

    $approved = test()->post('/oauth/authorize', ['auth_token' => $authToken, 'flow' => $flow]);
    $approved->assertRedirect();
    parse_str((string) parse_url((string) $approved->headers->get('Location'), PHP_URL_QUERY), $parameters);

    $exchange = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => $client->getAttribute('redirect_uris')[0],
        'code' => $parameters['code'] ?? '',
        'code_verifier' => str_repeat('a', 64),
    ])->assertOk();

    return (string) $exchange->json('access_token');
}

test('the session token endpoint mints a verifiable token for the signed-in session', function (): void {
    $user = User::factory()->create();

    $login = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    // Every completed sign-in records a first-factor verification.
    $login->assertSessionHas(FactorVerifications::SESSION_KEY, fn (array $stamps): bool => isset($stamps['first']));

    $sessionId = sessionTokenCookie($login, config('session.cookie'));
    $claims = sessionTokenClaims(
        $this->withCookie(config('session.cookie'), $sessionId)->get(route('session-token.show'))
    );

    expect($claims['sub'])->toBe((string) $user->getKey());
    expect($claims['iss'])->toBe(rtrim((string) config('app.url'), '/'));
    expect($claims['v'])->toBe('2');
    expect($claims['sid'])->toBe($sessionId);
    expect($claims['jti'])->toBeString()->not->toBe('');
    // The parser hands date claims back as DateTime objects.
    expect($claims['nbf']->getTimestamp())->toBe($claims['iat']->getTimestamp());
    expect($claims['exp']->getTimestamp() - $claims['iat']->getTimestamp())->toBe(SessionTokenService::LIFETIME_SECONDS);
    expect($claims['exp']->getTimestamp())->toBe($claims['__expires_at']);

    // The password sign-in verified a first factor moments ago; no second
    // factor has ever been verified here.
    expect($claims['fva'])->toBe([0, -1]);
    expect($claims['sts'])->toBe('active');
    expect($claims['tasks'])->toBe([]);

    // No application context, no impersonation, no Origin: those claims stay away.
    expect($claims)->not->toHaveKey('o');
    expect($claims)->not->toHaveKey('act');
    expect($claims)->not->toHaveKey('azp');
});

test('guests cannot mint session tokens', function (): void {
    $this->getJson(route('session-token.show'))->assertUnauthorized();
});

test('session tokens expire after one minute', function (): void {
    $user = User::factory()->create();
    $token = (new Parser(new JoseEncoder))->parse(
        (string) $this->actingAs($user)->getJson(route('session-token.show'))->json('token'),
    );

    $clock = new class implements ClockInterface
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('@'.now()->timestamp);
        }
    };

    $this->travel(SessionTokenService::LIFETIME_SECONDS + 1)->seconds();

    expect(fn () => (new Validator)->assert($token, new LooseValidAt($clock)))
        ->toThrow(RequiredConstraintsViolated::class);
});

test('the JWKS document publishes the key that verifies session tokens', function (): void {
    $user = User::factory()->create();
    $token = (new Parser(new JoseEncoder))->parse(
        (string) $this->actingAs($user)->getJson(route('session-token.show'))->json('token'),
    );

    $keys = $this->getJson('/oauth/jwks')->assertOk()->json('keys');
    expect($keys)->toHaveCount(1);

    // The header points at the published key, and the published modulus and
    // exponent are that key's — everything a backend needs to verify offline.
    expect($keys[0]['kid'])->toBe($token->headers()->get('kid'));
    expect($keys[0]['alg'])->toBe('RS256');

    $details = openssl_pkey_get_details(openssl_pkey_get_public((string) file_get_contents(storage_path('oauth-public.key'))));
    expect($keys[0]['n'])->toBe(rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='));
    expect($keys[0]['e'])->toBe(rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='));
});

test('factor verification ages follow the session verifications', function (): void {
    $user = User::factory()->create();

    $claims = sessionTokenClaims($this->actingAs($user)->get(route('session-token.show')));
    expect($claims['fva'])->toBe([-1, -1]);

    $claims = sessionTokenClaims(
        $this->actingAs($user)
            ->withSession([FactorVerifications::SESSION_KEY => [
                'first' => now()->subMinutes(3)->timestamp,
                'second' => now()->subMinutes(1)->timestamp,
            ]])
            ->get(route('session-token.show'))
    );
    expect($claims['fva'])->toBe([3, 1]);
});

test('completing the two factor challenge records a second factor verification', function (): void {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->withSession(['login.id' => $user->id])
        ->post(route('two-factor.login.store'), ['recovery_code' => 'code1']);

    $response->assertSessionHas(FactorVerifications::SESSION_KEY, fn (array $stamps): bool => isset($stamps['first'], $stamps['second']));

    $sessionId = sessionTokenCookie($response, config('session.cookie'));
    $claims = sessionTokenClaims(
        $this->withCookie(config('session.cookie'), $sessionId)->get(route('session-token.show'))
    );
    expect($claims['fva'])->toBe([0, 0]);
});

test('switching accounts never records a factor verification', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $registry = sessionTokenCookie(
        $this->post(route('login.store'), ['email' => $first->email, 'password' => 'password']),
        MultiSession::COOKIE,
    );
    $registry = sessionTokenCookie(
        $this->withCookie(MultiSession::COOKIE, $registry)
            ->post(route('login.store', ['add' => 1]), ['email' => $second->email, 'password' => 'password']),
        MultiSession::COOKIE,
    );

    $this->withCookie(MultiSession::COOKIE, $registry)
        ->post(route('accounts.switch'), ['user_id' => $first->getKey()])
        ->assertSessionMissing(FactorVerifications::SESSION_KEY);
});

test('impersonation claims an actor and never vouches for the subject', function (): void {
    $admin = User::factory()->create();
    $target = User::factory()->create();
    $manager = app('impersonate');

    $claims = sessionTokenClaims(
        $this->actingAs($target)->withSession([
            $manager->getSessionKey() => $admin->getKey(),
            // The impersonator's own verification is still in the session.
            FactorVerifications::SESSION_KEY => ['first' => now()->timestamp],
        ])->get(route('session-token.show'))
    );

    expect($claims['act'])->toBe([
        'iss' => rtrim((string) config('app.url'), '/'),
        'sid' => $claims['sid'],
        'sub' => (string) $admin->getKey(),
    ]);

    // It belongs to the impersonator, so it must not vouch for the subject.
    expect($claims['fva'])->toBe([-1, -1]);
});

test('session status reports pending email verification', function (): void {
    $unverified = User::factory()->create(['email_verified_at' => null]);

    $claims = sessionTokenClaims($this->actingAs($unverified)->get(route('session-token.show')));
    expect($claims['sts'])->toBe('pending');
    expect($claims['tasks'])->toBe([SessionTask::EmailVerification->value]);

    $verified = User::factory()->create();

    $claims = sessionTokenClaims($this->actingAs($verified)->get(route('session-token.show')));
    expect($claims['sts'])->toBe('active');
    expect($claims['tasks'])->toBe([]);
});

test('session status reflects an applications multi-factor requirement', function (): void {
    [, $environment] = sessionTokenEnvironment('session-token-mfa', ['require_mfa' => true]);

    $user = sessionTokenUser($environment);
    sessionTokenEnroll($user, $environment);

    $claims = sessionTokenClaims($this->actingAs($user)->get(route('session-token.show')));
    expect($claims['sts'])->toBe('pending');
    expect($claims['tasks'])->toBe([SessionTask::MultiFactor->value]);

    // A passkey sign-in satisfies the requirement on its own...
    $claims = sessionTokenClaims(
        $this->actingAs($user)
            ->withSession([ApplicationAuthentication::MFA_SATISFIED_BY_PASSKEY => true])
            ->get(route('session-token.show'))
    );
    expect($claims['sts'])->toBe('active');

    // ...and an enrolled second factor always does.
    $enrolled = sessionTokenUser($environment, [
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);
    sessionTokenEnroll($enrolled, $environment);

    $claims = sessionTokenClaims($this->actingAs($enrolled)->get(route('session-token.show')));
    expect($claims['sts'])->toBe('active');
});

test('the organization claim carries the organization and the users role', function (): void {
    [, $environment] = sessionTokenEnvironment('session-token-org');
    $user = sessionTokenUser($environment);
    sessionTokenEnroll($user, $environment, 'admin');

    $claims = sessionTokenClaims($this->actingAs($user)->get(route('session-token.show')));

    expect($claims['o'])->toBe([
        'id' => (string) $environment->application->tenant_id,
        'slg' => (string) $environment->application->tenant->slug,
        'rol' => 'admin',
    ]);

    // The application binding backends check, matching userinfo.
    expect($claims['application_id'])->toBe((string) $environment->application->getKey());
    expect($claims['environment'])->toBe('development');
});

test('the azp claim follows the Origin header', function (): void {
    $user = User::factory()->create();

    $claims = sessionTokenClaims($this->actingAs($user)->get(route('session-token.show')));
    expect($claims)->not->toHaveKey('azp');

    $claims = sessionTokenClaims(
        $this->actingAs($user)->withHeader('Origin', 'https://app.example')->get(route('session-token.show'))
    );
    expect($claims['azp'])->toBe('https://app.example');
});

test('access tokens mint session tokens for their user', function (): void {
    [$client, $environment] = sessionTokenEnvironment('session-token-sdk');
    $user = sessionTokenUser($environment);
    sessionTokenEnroll($user, $environment, 'member');

    $accessToken = sessionTokenCodeGrant($client, $user);

    $claims = sessionTokenClaims(
        $this->withToken($accessToken)->postJson('/api/oauth/session-token')
    );

    expect($claims['sub'])->toBe((string) $user->getKey());
    expect($claims['fva'])->toBe([-1, -1]);
    expect($claims['sts'])->toBe('active');
    expect($claims['application_id'])->toBe((string) $environment->application->getKey());
    expect($claims['environment'])->toBe('development');
    expect($claims['o'])->toBe([
        'id' => (string) $environment->application->tenant_id,
        'slg' => (string) $environment->application->tenant->slug,
        'rol' => 'member',
    ]);

    // The session behind an SDK mint is the grant the token belongs to.
    $tokenId = $user->tokens()->latest()->first()?->getKey();
    expect($claims['sid'])->toBe((string) $tokenId);
});

test('client credential tokens cannot mint session tokens', function (): void {
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('session token management');

    $issued = $this->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'scope' => 'users:read',
    ])->assertOk();

    $this->withToken((string) $issued->json('access_token'))
        ->postJson('/api/oauth/session-token')
        ->assertUnauthorized();
});

test('banned users cannot mint session tokens', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $user->ban();

    $this->getJson(route('session-token.show'))->assertForbidden();
});
