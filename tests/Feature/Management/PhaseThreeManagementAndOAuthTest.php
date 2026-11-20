<?php

declare(strict_types=1);

use App\Jobs\DispatchWebhookJob;
use App\Models\ManagementApiClient;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

function phaseThreeEnv(string $slug): array
{
    $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);
    $application = TenantApplication::query()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => $slug.' app',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://'.$slug.'.example/callback'],
    ]);
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($slug, ['https://'.$slug.'.example/callback']);
    $environment = $application->environments()->create([
        'environment' => 'development',
        'oauth_client_id' => $client->getKey(),
        'redirect_uris' => ['https://'.$slug.'.example/callback'],
        'settings' => ['allow_signups' => true],
    ]);

    $mgmtClient = app(ClientRepository::class)->createClientCredentialsGrantClient($slug.' mgmt');
    ManagementApiClient::query()->create([
        'tenant_application_environment_id' => $environment->getKey(),
        'oauth_client_id' => $mgmtClient->getKey(),
        'allowed_scopes' => ['users:read', 'users:write', 'sessions:read', 'sessions:revoke'],
        'is_active' => true,
    ]);

    $token = (string) postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $mgmtClient->getKey(),
        'client_secret' => $mgmtClient->plainSecret,
        'scope' => 'users:read users:write sessions:read sessions:revoke',
    ])->assertOk()->json('access_token');

    return [$tenant, $application, $environment, $client, $token];
}

test('management API supports full user lifecycle, search filters, and ban revocation', function (): void {
    Queue::fake([DispatchWebhookJob::class]);
    [, , $environment, $client, $token] = phaseThreeEnv('p3-users');

    WebhookEndpoint::query()->create([
        'name' => 'App Hooks',
        'url' => 'https://example.com/hook',
        'secret' => 'whsec_test_secret_123456',
        'tenant_application_environment_id' => $environment->getKey(),
        'events' => ['user.created', 'user.updated', 'user.deleted'],
        'is_active' => true,
    ]);

    $created = withToken($token)->postJson('/api/v1/users', [
        'name' => 'Ada Lovelace',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'username' => 'ada',
        'email' => 'ada@example.test',
        'password' => 'StrongPass123!',
        'email_verified' => true,
    ])->assertCreated();

    $userId = $created->json('data.id');
    expect($created->json('data.username'))->toBe('ada')
        ->and($created->json('data.email_verified'))->toBeTrue()
        ->and($created->json('data.banned'))->toBeFalse();

    withToken($token)->getJson('/api/v1/users?search=ada&banned=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $userId);

    withToken($token)->patchJson('/api/v1/users/'.$userId, [
        'first_name' => 'Augusta',
    ])->assertOk()->assertJsonPath('data.first_name', 'Augusta');

    $accessToken = Token::query()->create([
        'id' => str_repeat('1', 80),
        'user_id' => $userId,
        'client_id' => $client->getKey(),
        'scopes' => ['openid'],
        'revoked' => false,
        'expires_at' => now()->addHour(),
    ]);
    $refreshToken = RefreshToken::query()->create([
        'id' => str_repeat('2', 80),
        'access_token_id' => $accessToken->getKey(),
        'revoked' => false,
        'expires_at' => now()->addDay(),
    ]);

    withToken($token)->postJson('/api/v1/users/'.$userId.'/ban', ['reason' => 'Abuse'])
        ->assertOk()
        ->assertJsonPath('data.banned', true);

    expect($accessToken->refresh()->getAttribute('revoked'))->toBeTrue()
        ->and($refreshToken->refresh()->getAttribute('revoked'))->toBeTrue();

    withToken($token)->postJson('/api/v1/users/'.$userId.'/unban')
        ->assertOk()
        ->assertJsonPath('data.banned', false);

    withToken($token)->deleteJson('/api/v1/users/'.$userId)->assertNoContent();
    withToken($token)->getJson('/api/v1/users/'.$userId)->assertNotFound();

    Queue::assertPushed(DispatchWebhookJob::class, fn (DispatchWebhookJob $job): bool => $job->event === 'user.created');
    Queue::assertPushed(DispatchWebhookJob::class, fn (DispatchWebhookJob $job): bool => $job->event === 'user.deleted');
});

test('environment secret key authenticates without revoked client and fails when client is revoked', function (): void {
    [, , $environment, $client] = phaseThreeEnv('p3-secret-key');
    $material = TenantApplicationEnvironment::generateSecretKeyMaterial($environment->environment);
    $environment->forceFill([
        'secret_key_lookup' => $material['lookup'],
        'secret_key_hash' => $material['hash'],
        'secret_key_prefix' => $material['prefix'],
        'secret_key_rotated_at' => now(),
    ])->save();

    withToken($material['plain'])->getJson('/api/v1/users')->assertOk();

    $client->forceFill(['revoked' => true])->save();
    withToken($material['plain'])->getJson('/api/v1/users')->assertUnauthorized();
});

test('replaying a rotated refresh token revokes the entire grant family', function (): void {
    [$tenant, $application, $environment, $client] = phaseThreeEnv('p3-replay');
    $environment->update([
        'settings' => array_merge($environment->settings ?? [], ['oauth_consent_enabled' => true]),
    ]);
    $user = User::factory()->forEnvironment($environment)->create();
    $tenant->users()->attach($user, ['role' => 'member']);
    $application->users()->attach($user, ['role' => 'member', 'environment' => 'development']);

    $verifier = str_repeat('b', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $start = $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://p3-replay.example/callback',
        'response_type' => 'code',
        'scope' => 'openid',
        'state' => 'replay-state',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    $flow = $start->viewData('flow');
    $login = $this->post('/login', [
        'flow' => $flow,
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();
    $authorization = $this->get((string) $login->headers->get('Location'))->assertOk();
    $approval = $this->post('/oauth/authorize', [
        'flow' => $flow,
        'state' => 'replay-state',
        'client_id' => $client->getKey(),
        'auth_token' => $authorization->original->getData()['page']['props']['authToken'],
    ])->assertRedirect();

    parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $callbackQuery);

    $initial = postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => 'https://p3-replay.example/callback',
        'code_verifier' => $verifier,
        'code' => $callbackQuery['code'],
    ])->assertOk();

    $firstRefresh = (string) $initial->json('refresh_token');

    // Rotate once legitimately.
    postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'refresh_token' => $firstRefresh,
    ])->assertOk();

    // Replay the already-rotated refresh token.
    postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'refresh_token' => $firstRefresh,
    ])->assertStatus(400);

    $family = DB::table('oauth_grant_families')->where('client_id', $client->getKey())->first();
    expect($family)->not->toBeNull()
        ->and($family->revoked_at)->not->toBeNull();
});
