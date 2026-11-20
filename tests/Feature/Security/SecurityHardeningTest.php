<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\WebhookUrlValidator;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

test('public oauth client requires S256 PKCE even when requested by raw oauth_client_id', function (): void {
    $client = (new Client)->forceFill([
        'name' => 'Public SPA',
        'secret' => null,
        'redirect_uris' => ['https://spa.example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ]);
    $client->save();

    $tenant = Tenant::query()->create(['name' => 'PKCE Org', 'slug' => 'pkce-org', 'is_active' => true]);
    $application = $tenant->applications()->create([
        'name' => 'Public SPA',
        'framework' => 'nextjs',
        'environment' => 'development',
        'redirect_uris' => ['https://spa.example.com/callback'],
        'oauth_client_id' => $client->getKey(),
    ]);
    $application->environments()->create([
        'environment' => 'development',
        'oauth_client_id' => $client->getKey(),
        'redirect_uris' => ['https://spa.example.com/callback'],
        'settings' => [],
    ]);

    get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://spa.example.com/callback',
        'response_type' => 'code',
        'state' => 'abc',
        'scope' => 'openid',
    ]))->assertStatus(400);
});

test('userinfo requires openid scope and defaults empty pivot role to member', function (): void {
    $user = User::factory()->create();
    $client = (new Client)->forceFill([
        'name' => 'OIDC App',
        'secret' => 'secret',
        'redirect_uris' => ['https://oidc.example.com/callback'],
        'grant_types' => ['authorization_code'],
        'revoked' => false,
    ]);
    $client->save();

    $tenant = Tenant::query()->create(['name' => 'OIDC Org', 'slug' => 'oidc-org', 'is_active' => true]);
    $application = $tenant->applications()->create([
        'name' => 'OIDC App',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://oidc.example.com/callback'],
        'oauth_client_id' => $client->getKey(),
    ]);
    $environment = $application->environments()->create([
        'environment' => 'development',
        'oauth_client_id' => $client->getKey(),
        'redirect_uris' => ['https://oidc.example.com/callback'],
        'settings' => [],
    ]);
    $tenant->users()->attach($user);
    $application->users()->attach($user, ['environment' => 'development', 'role' => '']);

    Passport::actingAs($user, ['profile'], 'api', $client);
    getJson('/api/oauth/userinfo')->assertForbidden();

    Passport::actingAs($user, ['openid', 'roles'], 'api', $client);
    getJson('/api/oauth/userinfo')
        ->assertOk()
        ->assertJsonPath('roles.0', 'member')
        ->assertJsonPath('environment', $environment->environment);
});

test('webhook url validator blocks loopback, link-local, and private ip targets', function (): void {
    expect(WebhookUrlValidator::validate('https://localhost/hook'))->not->toBeNull();
    expect(WebhookUrlValidator::validate('https://127.0.0.1/hook'))->not->toBeNull();
    expect(WebhookUrlValidator::validate('https://169.254.169.254/latest/meta-data'))->not->toBeNull();
    expect(WebhookUrlValidator::validate('https://10.0.0.5/hook'))->not->toBeNull();
    expect(WebhookUrlValidator::validate('https://192.168.1.10/hook'))->not->toBeNull();
    expect(WebhookUrlValidator::validate('https://example.com/webhooks/koamishin'))->toBeNull();
});

test('webhook endpoint policy isolates endpoints by tenant for non-super-admins', function (): void {
    foreach (['View:WebhookEndpoint', 'Update:WebhookEndpoint', 'Delete:WebhookEndpoint'] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }
    $role = Role::findOrCreate('tenant_admin', 'web');
    $role->givePermissionTo(['View:WebhookEndpoint', 'Update:WebhookEndpoint', 'Delete:WebhookEndpoint']);

    $user = User::factory()->create();
    $user->assignRole($role);

    $ownTenant = Tenant::query()->create(['name' => 'Own', 'slug' => 'own', 'is_active' => true]);
    $ownTenant->users()->attach($user, ['role' => 'owner']);
    $ownApp = TenantApplication::query()->create([
        'tenant_id' => $ownTenant->getKey(),
        'name' => 'Own App',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => [],
    ]);
    $ownEnv = $ownApp->environments()->create(['environment' => 'development', 'redirect_uris' => []]);
    $ownEndpoint = WebhookEndpoint::query()->create([
        'name' => 'Own Hook',
        'url' => 'https://example.com/own',
        'secret' => 'whsec_secret1234567890123456',
        'tenant_application_environment_id' => $ownEnv->getKey(),
        'events' => ['user.created'],
        'is_active' => true,
    ]);

    $otherTenant = Tenant::query()->create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
    $otherApp = TenantApplication::query()->create([
        'tenant_id' => $otherTenant->getKey(),
        'name' => 'Other App',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => [],
    ]);
    $otherEnv = $otherApp->environments()->create(['environment' => 'development', 'redirect_uris' => []]);
    $otherEndpoint = WebhookEndpoint::query()->create([
        'name' => 'Other Hook',
        'url' => 'https://example.com/other',
        'secret' => 'whsec_secret1234567890123456',
        'tenant_application_environment_id' => $otherEnv->getKey(),
        'events' => ['user.created'],
        'is_active' => true,
    ]);

    expect(Gate::forUser($user)->allows('view', $ownEndpoint))->toBeTrue();
    expect(Gate::forUser($user)->allows('update', $ownEndpoint))->toBeTrue();
    expect(Gate::forUser($user)->allows('view', $otherEndpoint))->toBeFalse();
    expect(Gate::forUser($user)->allows('update', $otherEndpoint))->toBeFalse();
    expect(Gate::forUser($user)->allows('delete', $otherEndpoint))->toBeFalse();
});

test('sensitive secrets are hidden from model serialization', function (): void {
    $endpoint = new WebhookEndpoint([
        'name' => 'Hook',
        'url' => 'https://example.com/hook',
        'secret' => 'super-secret',
    ]);
    $social = new SocialAccount([
        'provider' => 'github',
        'provider_id' => '123',
        'access_token' => 'gho_secret',
        'refresh_token' => 'ghr_secret',
    ]);

    expect($endpoint->toArray())->not->toHaveKey('secret');
    expect($social->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);
});
