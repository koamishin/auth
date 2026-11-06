<?php

use App\Filament\Resources\TenantApplications\Pages\ListApplicationUsers;
use App\Filament\Resources\TenantApplications\Pages\ViewApplicationUser;
use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\ApplicationUserMetadata;
use App\Models\Session;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * @return array{0: Tenant, 1: TenantApplication, 2: TenantApplicationEnvironment, 3: User}
 */
function userManagementContext(string $slug): array
{
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
    $application = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => $slug.' app',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://'.$slug.'.internal/callback'],
    ]);

    $environment = $application->environments()->where('environment', 'development')->sole();

    test()->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    return [$tenant, $application, $environment, $admin];
}

function enrollMember(TenantApplication $application, TenantApplicationEnvironment $environment, array $attributes = []): User
{
    $user = User::factory()->create([
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
        ...$attributes,
    ]);

    $application->users($environment->environment)->attach($user, [
        'role' => 'member',
        'environment' => $environment->environment,
        'last_sign_in_at' => now()->subDay(),
    ]);

    return $user;
}

test('the users table lists enrolled accounts with their role, status, and auth methods', function (): void {
    [$tenant, $application, $environment] = userManagementContext('users-list');

    $member = enrollMember($application, $environment, [
        'name' => 'Ada Lovelace',
        'email' => 'ada@users-list.test',
        'username' => 'ada',
    ]);

    $banned = enrollMember($application, $environment, [
        'name' => 'Grace Hopper',
        'email' => 'grace@users-list.test',
    ]);
    $banned->ban('Abuse');

    // A user enrolled in another application must not leak into this table.
    $other = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Other app',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://other.internal/callback'],
    ]);
    enrollMember($other, $other->environments()->where('environment', 'development')->sole(), [
        'name' => 'Outsider',
        'email' => 'outsider@users-list.test',
    ]);

    $response = $this->get(TenantApplicationResource::getUrl('users', [
        'tenant' => $tenant,
        'record' => $application,
    ]))->assertOk();

    $response->assertSee('Ada Lovelace')
        ->assertSee('ada@users-list.test')
        ->assertSee('Grace Hopper')
        ->assertSee('Suspended')
        ->assertSee('Active')
        ->assertSee('Auth methods')
        ->assertSee('Last sign in')
        ->assertSee('Enrolled')
        ->assertDontSee('outsider@users-list.test');
});

test('the users table only lists accounts enrolled in the active environment', function (): void {
    [$tenant, $application, $environment] = userManagementContext('users-environment');

    $development = enrollMember($application, $environment, ['name' => 'Dev User']);

    $production = $application->environments()->where('environment', 'production')->sole();
    $productionUser = enrollMember($application, $production, ['name' => 'Prod User']);

    $this->get(TenantApplicationResource::getUrl('users', ['tenant' => $tenant, 'record' => $application]))
        ->assertOk()
        ->assertSee($development->name)
        ->assertDontSee($productionUser->name);

    $application->update(['environment' => 'production']);

    $this->get(TenantApplicationResource::getUrl('users', ['tenant' => $tenant, 'record' => $application]))
        ->assertOk()
        ->assertSee($productionUser->name)
        ->assertDontSee($development->name);
});

test('an application user profile exposes identifiers, authentication, sessions, metadata, and membership', function (): void {
    [$tenant, $application, $environment] = userManagementContext('users-profile');

    $member = enrollMember($application, $environment, [
        'name' => 'Ada Lovelace',
        'email' => 'ada@users-profile.test',
        'username' => 'ada',
        'phone_number' => '+15550001111',
        'web3_wallet' => '0xabc',
    ]);

    Session::query()->create([
        'id' => 'session-one',
        'user_id' => $member->getKey(),
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        'payload' => 'payload',
        'last_activity' => now()->subMinutes(5)->timestamp,
    ]);

    ApplicationUserMetadata::query()->create([
        'tenant_application_environment_id' => $environment->getKey(),
        'user_id' => $member->getKey(),
        'public_metadata' => ['plan' => 'pro'],
        'private_metadata' => ['seats' => 4],
    ]);

    $response = $this->get(TenantApplicationResource::getUrl('user', [
        'tenant' => $tenant,
        'record' => $application,
        'userId' => $member->getKey(),
    ]))->assertOk();

    $response->assertSee('Ada Lovelace')
        ->assertSee('Identifiers')
        ->assertSee('Email address')
        ->assertSee('Phone number')
        ->assertSee('Username')
        ->assertSee('Web3 wallet')
        ->assertSee('Authentication')
        ->assertSee('Passkeys')
        ->assertSee('Connected accounts')
        ->assertSee('Sessions')
        ->assertSee('203.0.113.10')
        ->assertSee('Metadata')
        ->assertSee('plan')
        ->assertSee('seats')
        ->assertSee('Membership')
        ->assertSee('Danger zone')
        ->assertSee('ada')
        ->assertSee('+15550001111');
});

test('application user accounts can be suspended, restored, and have their sessions revoked', function (): void {
    [$tenant, $application, $environment, $admin] = userManagementContext('users-moderation');

    $member = enrollMember($application, $environment);

    Session::query()->create([
        'id' => 'session-two',
        'user_id' => $member->getKey(),
        'ip_address' => '203.0.113.11',
        'user_agent' => 'Mozilla/5.0',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);

    $page = fn () => Livewire::actingAs($admin)->test(ListApplicationUsers::class, [
        'record' => $application->getKey(),
    ])->assertOk();

    $page()->callTableAction('ban', $member->getKey(), ['reason' => 'Abuse'])->assertHasNoErrors();
    expect($member->refresh()->isBanned())->toBeTrue();
    expect($member->ban_reason)->toBe('Abuse');
    expect(Session::query()->where('user_id', $member->getKey())->count())->toBe(0);

    $page()->callTableAction('unban', $member->getKey())->assertHasNoErrors();
    expect($member->refresh()->isBanned())->toBeFalse();

    Session::query()->create([
        'id' => 'session-three',
        'user_id' => $member->getKey(),
        'ip_address' => '203.0.113.12',
        'user_agent' => 'Mozilla/5.0',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);

    $page()->callTableAction('revokeSessions', $member->getKey())->assertHasNoErrors();
    expect(Session::query()->where('user_id', $member->getKey())->count())->toBe(0);
});

test('an email address can be verified from the users table without the user confirming it', function (): void {
    [$tenant, $application, $environment, $admin] = userManagementContext('users-verify');

    $member = enrollMember($application, $environment, ['email_verified_at' => null]);
    expect($member->hasVerifiedEmail())->toBeFalse();

    Livewire::actingAs($admin)->test(ListApplicationUsers::class, ['record' => $application->getKey()])
        ->callTableAction('verifyEmail', $member->getKey())
        ->assertHasNoErrors();

    expect($member->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('a profile can terminate one session, remove a passkey, and disconnect a social account', function (): void {
    [$tenant, $application, $environment, $admin] = userManagementContext('users-revoke');

    $member = enrollMember($application, $environment);

    $session = Session::query()->create([
        'id' => 'session-four',
        'user_id' => $member->getKey(),
        'ip_address' => '203.0.113.13',
        'user_agent' => 'Mozilla/5.0',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);

    $passkey = $member->passkeys()->create([
        'name' => 'MacBook Touch ID',
        'credential_id' => 'credential-one',
        'credential' => ['aaguid' => 'test'],
    ]);

    $account = $member->socialAccounts()->create([
        'provider' => 'github',
        'provider_id' => 'gh-123',
        'connection_scope' => 'portal',
        'nickname' => 'ada',
    ]);

    $page = fn () => Livewire::actingAs($admin)->test(ViewApplicationUser::class, [
        'record' => $application->getKey(),
        'userId' => $member->getKey(),
    ])->assertOk();

    $page()->call('revokeSession', $session->getKey())->assertHasNoErrors();
    expect(Session::query()->whereKey('session-four')->exists())->toBeFalse();

    $page()->call('deletePasskey', $passkey->getKey())->assertHasNoErrors();
    expect($member->passkeys()->count())->toBe(0);

    $page()->call('disconnectSocialAccount', $account->getKey())->assertHasNoErrors();
    expect($member->socialAccounts()->count())->toBe(0);
});

test('metadata edited from a profile is validated against the application schema', function (): void {
    [$tenant, $application, $environment, $admin] = userManagementContext('users-metadata');

    $environment->update(['settings' => [
        ...$environment->settings,
        'user_metadata_attributes' => [
            ['key' => 'plan', 'type' => 'string', 'visibility' => 'public'],
        ],
    ]]);

    $member = enrollMember($application, $environment);

    $page = fn () => Livewire::actingAs($admin)->test(ViewApplicationUser::class, [
        'record' => $application->getKey(),
        'userId' => $member->getKey(),
    ])->assertOk();

    $page()->call('updateMetadata', ['plan' => 'pro'], [])->assertHasNoErrors();

    $metadata = ApplicationUserMetadata::query()->where('user_id', $member->getKey())->sole();
    expect($metadata->public_metadata->plan)->toBe('pro');

    // Undeclared attributes are rejected and nothing is written.
    $page()->call('updateMetadata', ['undeclared' => 'value'], [])->assertHasErrors();

    expect(ApplicationUserMetadata::query()->where('user_id', $member->getKey())->sole()->public_metadata->plan)->toBe('pro');
});

test('a user profile only resolves accounts enrolled in that application and environment', function (): void {
    [$tenant, $application, $environment] = userManagementContext('users-scope');

    $member = enrollMember($application, $environment);

    $other = app(ApplicationProvisioner::class)->create($tenant, [
        'name' => 'Scope other',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => ['https://scope-other.internal/callback'],
    ]);

    $this->get(TenantApplicationResource::getUrl('user', [
        'tenant' => $tenant,
        'record' => $other,
        'userId' => $member->getKey(),
    ]))->assertNotFound();

    $unrelated = User::factory()->create(['identity_namespace' => User::PORTAL_IDENTITY_NAMESPACE]);

    $this->get(TenantApplicationResource::getUrl('user', [
        'tenant' => $tenant,
        'record' => $application,
        'userId' => $unrelated->getKey(),
    ]))->assertNotFound();
});

test('application user management requires panel access and the user listing permission', function (): void {
    [$tenant, $application, $environment] = userManagementContext('users-permissions');

    $member = enrollMember($application, $environment);

    $outsider = User::factory()->create();
    $this->actingAs($outsider);

    $this->get(TenantApplicationResource::getUrl('users', [
        'tenant' => $tenant,
        'record' => $application,
    ]))->assertForbidden();

    $this->get(TenantApplicationResource::getUrl('user', [
        'tenant' => $tenant,
        'record' => $application,
        'userId' => $member->getKey(),
    ]))->assertForbidden();
});
