<?php

declare(strict_types=1);

use App\Filament\Resources\TenantApplications\Pages\ViewTenantApplication;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

test('unconnected applications issue separate environment keys without creating oauth clients', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Keys', 'slug' => 'keys']);
    $application = app(ApplicationProvisioner::class)->create($tenant, ['name' => 'Example']);
    $credentials = session('new_app_credentials');
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();

    expect($application->framework)->toBeNull()
        ->and($application->oauth_client_id)->toBeNull()
        ->and($development->oauth_client_id)->toBeNull()
        ->and($production->oauth_client_id)->toBeNull()
        ->and($development->publishable_key)->toStartWith('pk_test_')
        ->and($production->publishable_key)->toStartWith('pk_live_')
        ->and($credentials['development']['secret_key'])->toStartWith('sk_test_')
        ->and($credentials['production']['secret_key'])->toStartWith('sk_live_')
        ->and($development->verifySecretKey($credentials['development']['secret_key']))->toBeTrue()
        ->and($development->verifySecretKey($credentials['production']['secret_key']))->toBeFalse()
        ->and(Hash::check($credentials['development']['secret_key'], $development->secret_key_hash))->toBeTrue()
        ->and($development->toJson())->not->toContain($credentials['development']['secret_key']);
});

test('secret key management access and rotation stay within the application environment', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Management keys', 'slug' => 'management-keys']);
    $application = app(ApplicationProvisioner::class)->create($tenant, ['name' => 'Example']);
    $credentials = session('new_app_credentials');
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();

    withToken($development->publishable_key)->getJson('/api/v1/organization')->assertUnauthorized();
    withToken($credentials['development']['secret_key'])->getJson('/api/v1/organization')
        ->assertOk()->assertJsonPath('data.application_id', (string) $application->id)
        ->assertJsonPath('data.environment', 'development');
    withToken($credentials['production']['secret_key'])->getJson('/api/v1/organization')
        ->assertOk()->assertJsonPath('data.environment', 'production');

    $rotated = app(ApplicationProvisioner::class)->rotateSecretKey($application, 'development');
    withToken($credentials['development']['secret_key'])->getJson('/api/v1/organization')->assertUnauthorized();
    withToken($rotated['secret_key'])->getJson('/api/v1/organization')->assertOk();
    withToken($credentials['production']['secret_key'])->getJson('/api/v1/organization')->assertOk();
    expect($production->fresh()->verifySecretKey($credentials['production']['secret_key']))->toBeTrue();

    $tenant->update(['is_active' => false]);
    withToken($rotated['secret_key'])->getJson('/api/v1/organization')->assertForbidden();
});

test('connecting an environment refuses to change an oauth client shared by another environment', function (string $framework, string $clientType): void {
    $tenant = Tenant::query()->create(['name' => 'Shared', 'slug' => 'shared-key-client']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Example', 'framework' => $framework, 'environment' => 'development',
        'redirect_uris' => ['http://localhost:3000/callback'],
    ]);
    $development = $application->environments()->where('environment', 'development')->sole();
    $production = $application->environments()->where('environment', 'production')->sole();
    $production->update(['oauth_client_id' => $development->oauth_client_id]);

    expect(fn () => app(ApplicationProvisioner::class)->connectEnvironment($application, 'development', [
        'framework' => $framework, 'client_type' => $clientType,
        'redirect_uris' => ['http://localhost:3000/other'],
    ]))->toThrow(ValidationException::class);
    expect($development->oauthClient->fresh()->redirect_uris)->toBe(['http://localhost:3000/callback']);
    if ($clientType === 'confidential') {
        expect(fn () => app(ApplicationProvisioner::class)->rotateLegacyClientSecret($application))
            ->toThrow(ValidationException::class);
    }
})->with([['react', 'public'], ['laravel', 'confidential']]);

test('a secret key cannot read users enrolled only in a different application or environment', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Isolation', 'slug' => 'key-isolation']);
    $application = app(ApplicationProvisioner::class)->create($tenant, ['name' => 'First']);
    $credentials = session('new_app_credentials');
    $otherApplication = app(ApplicationProvisioner::class)->create($tenant, ['name' => 'Second']);
    $user = User::factory()->create(['identity_namespace' => $application->getEnvironmentConfig('production')->identityNamespace()]);
    $tenant->users()->attach($user);
    $otherApplication->users()->attach($user, ['environment' => 'development', 'role' => 'member']);
    $application->users()->attach($user, ['environment' => 'production', 'role' => 'member']);

    withToken($credentials['development']['secret_key'])->getJson('/api/v1/users/'.$user->id)->assertNotFound();
    withToken($credentials['production']['secret_key'])->getJson('/api/v1/users/'.$user->id)->assertOk();
});

test('credentials flashed for one application are not displayed on another application', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Credentials', 'slug' => 'key-credentials']);
    $first = app(ApplicationProvisioner::class)->create($tenant, ['name' => 'First']);
    $credentials = session('new_app_credentials');
    $second = app(ApplicationProvisioner::class)->create($tenant, ['name' => 'Second']);
    session([
        'new_app_credentials' => $credentials,
        'new_app_credentials_application_id' => $first->getKey(),
    ]);
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)
        ->test(ViewTenantApplication::class, ['record' => $first->id])
        ->assertSee($credentials['development']['secret_key'])
        ->assertSee($credentials['production']['secret_key']);
    Livewire::actingAs($admin)
        ->test(ViewTenantApplication::class, ['record' => $second->id])
        ->assertDontSee($credentials['development']['secret_key'])
        ->assertDontSee($credentials['production']['secret_key']);
});

test('publishable keys complete oauth authorization and token refresh for each client type', function (string $clientType): void {
    $tenant = Tenant::query()->create(['name' => 'OAuth keys', 'slug' => 'oauth-keys']);
    $application = app(ApplicationProvisioner::class)->create($tenant, ['name' => 'Example']);
    $credentials = session('new_app_credentials');
    $environment = app(ApplicationProvisioner::class)->connectEnvironment($application, 'development', [
        'framework' => $clientType === 'public' ? 'react' : 'laravel',
        'client_type' => $clientType,
        'redirect_uris' => ['http://localhost:3000/callback'],
    ]);
    $environment->update(['settings' => array_merge($environment->settings ?? [], ['oauth_consent_enabled' => true])]);
    $user = User::factory()->create(['identity_namespace' => $environment->identityNamespace()]);
    $tenant->users()->attach($user);
    $application->users()->attach($user, ['environment' => 'development', 'role' => 'member']);
    $verifier = str_repeat('a', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $query = [
        'client_id' => $environment->publishable_key,
        'redirect_uri' => 'http://localhost:3000/callback',
        'response_type' => 'code',
        'scope' => 'openid',
        'state' => 'state',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];
    if ($clientType === 'public') {
        $withoutChallenge = $query;
        unset($withoutChallenge['code_challenge'], $withoutChallenge['code_challenge_method']);
        get('/oauth/authorize?'.http_build_query($withoutChallenge))->assertBadRequest();
        get('/oauth/authorize?'.http_build_query([...$query, 'code_challenge_method' => 'plain']))->assertBadRequest();
    }
    $start = get('/oauth/authorize?'.http_build_query($query))->assertOk();
    $flow = $start->viewData('flow');
    $login = post('/login', ['flow' => $flow, 'email' => $user->email, 'password' => 'password'])->assertRedirect();
    $page = get((string) $login->headers->get('Location'))->assertOk();
    $approval = post('/oauth/authorize', [
        'flow' => $flow,
        'state' => 'state',
        'client_id' => $environment->oauth_client_id,
        'auth_token' => $page->original->getData()['page']['props']['authToken'],
    ])->assertRedirect();
    parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $callback);
    $payload = [
        'grant_type' => 'authorization_code',
        'client_id' => $environment->publishable_key,
        'redirect_uri' => 'http://localhost:3000/callback',
        'code_verifier' => $verifier,
        'code' => $callback['code'],
    ];

    if ($clientType === 'confidential') {
        postJson('/oauth/token', [...$payload, 'client_secret' => $credentials['production']['secret_key']])
            ->assertStatus(401);
        $payload['client_secret'] = $credentials['development']['secret_key'];
    }

    $tokens = postJson('/oauth/token', $payload)->assertOk();
    withToken($tokens->json('access_token'))->getJson('/api/oauth/userinfo')
        ->assertOk()->assertJsonPath('application_id', (string) $application->id)
        ->assertJsonPath('environment', 'development');
    $refresh = [
        'grant_type' => 'refresh_token',
        'client_id' => $environment->publishable_key,
        'refresh_token' => $tokens->json('refresh_token'),
    ];
    if ($clientType === 'confidential') {
        $refresh['client_secret'] = $credentials['development']['secret_key'];
    }
    postJson('/oauth/token', $refresh)->assertOk();
})->with(['public', 'confidential']);

test('rotating the environment secret leaves the legacy oauth secret unchanged and shows the new key', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $tenant = Tenant::query()->create(['name' => 'Rotate keys', 'slug' => 'rotate-keys']);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Example', 'framework' => 'laravel', 'environment' => 'development',
        'redirect_uris' => ['http://localhost:8000/callback'],
    ]);
    $before = session('new_app_credentials.development.secret_key');
    $client = Client::query()->findOrFail($application->oauth_client_id);
    $legacyHash = $client->secret;
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    $component = Livewire::actingAs($admin)
        ->test(ViewTenantApplication::class, ['record' => $application->id])
        ->callAction('rotateSecretKey')
        ->assertHasNoActionErrors();
    $rotated = $component->get('rotatedSecretKey.secret_key');
    $component->assertSee($rotated);

    expect($application->getEnvironmentConfig()->fresh()->verifySecretKey($before))->toBeFalse()
        ->and($application->getEnvironmentConfig()->fresh()->verifySecretKey($rotated))->toBeTrue()
        ->and($client->fresh()->secret)->toBe($legacyHash);
});

test('authorization errors explain callback and client checks without exposing request values', function (): void {
    $tenant = Tenant::query()->create(['name' => 'OAuth error', 'slug' => 'oauth-error']);
    $application = app(ApplicationProvisioner::class)->create($tenant, ['name' => 'Example']);
    $environment = app(ApplicationProvisioner::class)->connectEnvironment($application, 'development', [
        'framework' => 'nextjs',
        'client_type' => 'public',
        'redirect_uris' => ['http://localhost:3000/auth/callback'],
    ]);
    $query = [
        'client_id' => $environment->publishable_key,
        'redirect_uri' => 'http://localhost:3000/api/auth/callback',
        'response_type' => 'code',
        'scope' => 'openid',
        'state' => 'private-state-value',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ];

    get('/oauth/authorize?'.http_build_query($query))
        ->assertBadRequest()
        ->assertSee('application credentials or callback URL')
        ->assertSee('Compare the requested')
        ->assertDontSee('private-state-value')
        ->assertDontSee($environment->publishable_key)
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

test('authorization errors distinguish unsupported scopes and missing S256', function (): void {
    $tenant = Tenant::query()->create(['name' => 'OAuth checks', 'slug' => 'oauth-checks']);
    $application = app(ApplicationProvisioner::class)->create($tenant, ['name' => 'Example']);
    $environment = app(ApplicationProvisioner::class)->connectEnvironment($application, 'development', [
        'framework' => 'nextjs',
        'client_type' => 'public',
        'redirect_uris' => ['http://localhost:3000/auth/callback'],
    ]);
    $query = [
        'client_id' => $environment->publishable_key,
        'redirect_uri' => 'http://localhost:3000/auth/callback',
        'response_type' => 'code',
        'scope' => 'unsupported-scope',
        'state' => 'state',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ];

    get('/oauth/authorize?'.http_build_query($query))->assertBadRequest()->assertSee('unsupported or malformed scope');
    get('/oauth/authorize?'.http_build_query([...$query, 'scope' => 'openid', 'code_challenge_method' => 'plain']))
        ->assertBadRequest()->assertSee('must use S256 PKCE');
});
