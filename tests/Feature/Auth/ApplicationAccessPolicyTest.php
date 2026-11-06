<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\AccessMode;
use App\Filament\Resources\TenantApplications\Pages\AuthenticationTenantApplication;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Auth\ApplicationAccessPolicy;
use App\Services\OAuth\ApplicationAuthentication;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * @return array{0: TenantApplication, 1: TenantApplicationEnvironment}
 */
function accessEnvironment(string $slug, array $settings = []): array
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

function bindAccessEnvironment(?TenantApplicationEnvironment $environment): void
{
    request()->attributes->set('koamishin.application_environment', $environment);
}

test('the access mode decides whether self-service sign-up is possible', function (AccessMode $mode, bool $allows): void {
    [, $environment] = accessEnvironment('access-mode-'.str_replace('_', '-', $mode->value), ['access_mode' => $mode->value]);

    expect($environment->accessMode())->toBe($mode);
    expect($environment->allowsPublicSignup())->toBe($allows);
    expect(app(ApplicationAuthentication::class)->allowsSignups($environment))->toBe($allows);
})->with([
    'open' => [AccessMode::Open, true],
    'invite only' => [AccessMode::InviteOnly, false],
    'waitlist' => [AccessMode::Waitlist, false],
]);

test('applications without an access mode fall back to the legacy signup flag', function (bool $allowSignups, AccessMode $expected): void {
    [, $environment] = accessEnvironment('access-legacy-'.($allowSignups ? 'on' : 'off'), [
        'allow_signups' => $allowSignups,
    ]);

    expect($environment->accessMode())->toBe($expected);
    expect($environment->allowsPublicSignup())->toBe($allowSignups);
})->with([
    'legacy open' => [true, AccessMode::Open],
    'legacy closed' => [false, AccessMode::InviteOnly],
]);

test('a block rule refuses an exact address and a whole domain', function (): void {
    [, $environment] = accessEnvironment('access-block', [
        'access_rules' => [
            ['type' => 'block', 'kind' => 'email', 'value' => 'blocked@example.com'],
            ['type' => 'block', 'kind' => 'domain', 'value' => 'spam.example'],
        ],
    ]);

    $policy = app(ApplicationAccessPolicy::class);

    expect($policy->allows($environment, 'blocked@example.com'))->toBeFalse();
    expect($policy->allows($environment, 'anyone@spam.example'))->toBeFalse();
    expect($policy->allows($environment, 'anyone@mail.spam.example'))->toBeFalse();
    expect($policy->allows($environment, 'allowed@example.com'))->toBeTrue();
});

test('an allowlist restricts access to the addresses it names', function (): void {
    [, $environment] = accessEnvironment('access-allow', [
        'access_rules' => [
            ['type' => 'allow', 'kind' => 'domain', 'value' => 'partner.example'],
            ['type' => 'allow', 'kind' => 'email', 'value' => 'vip@example.com'],
        ],
    ]);

    $policy = app(ApplicationAccessPolicy::class);

    expect($policy->allows($environment, 'someone@partner.example'))->toBeTrue();
    expect($policy->allows($environment, 'vip@example.com'))->toBeTrue();
    expect($policy->allows($environment, 'stranger@example.com'))->toBeFalse();

    // A block rule still wins over an allow rule.
    $environment->update(['settings' => [...$environment->settings, 'access_rules' => [
        ['type' => 'allow', 'kind' => 'domain', 'value' => 'partner.example'],
        ['type' => 'block', 'kind' => 'email', 'value' => 'bad@partner.example'],
    ]]]);

    expect($policy->allows($environment->refresh(), 'bad@partner.example'))->toBeFalse();
});

test('an empty allowlist does not restrict anything', function (): void {
    [, $environment] = accessEnvironment('access-allow-empty', [
        'access_rules' => [['type' => 'block', 'kind' => 'domain', 'value' => 'spam.example']],
    ]);

    expect(app(ApplicationAccessPolicy::class)->allows($environment, 'anyone@example.com'))->toBeTrue();
});

test('subaddresses and disposable domains can be refused', function (): void {
    [, $environment] = accessEnvironment('access-protections', [
        'block_email_subaddresses' => true,
        'block_disposable_emails' => true,
    ]);

    $policy = app(ApplicationAccessPolicy::class);

    expect($policy->allows($environment, 'person+tag@example.com'))->toBeFalse();
    expect($policy->allows($environment, 'person@example.com'))->toBeTrue();
    expect($policy->allows($environment, 'person@mailinator.com'))->toBeFalse();
    expect($policy->allows($environment, 'person@sub.mailinator.com'))->toBeFalse();

    // Turning the protections off allows them again.
    $environment->update(['settings' => [
        ...$environment->settings,
        'block_email_subaddresses' => false,
        'block_disposable_emails' => false,
    ]]);

    expect($policy->allows($environment->refresh(), 'person+tag@example.com'))->toBeTrue();
    expect($policy->allows($environment->refresh(), 'person@mailinator.com'))->toBeTrue();
});

test('restrictions do not apply outside an application', function (): void {
    expect(app(ApplicationAccessPolicy::class)->allows(new TenantApplicationEnvironment, null))->toBeTrue();
    expect(app(ApplicationAccessPolicy::class)->allows(new TenantApplicationEnvironment, 'not-an-email'))->toBeTrue();
});

test('a blocked address cannot sign up', function (): void {
    [, $environment] = accessEnvironment('access-signup-block', [
        'access_mode' => AccessMode::Open->value,
        'access_rules' => [['type' => 'block', 'kind' => 'domain', 'value' => 'spam.example']],
    ]);

    bindAccessEnvironment($environment);

    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Blocked Person',
        'email' => 'person@spam.example',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
    ]))->toThrow(ValidationException::class);

    expect(User::query()->where('email', 'person@spam.example')->exists())->toBeFalse();

    $user = app(CreateNewUser::class)->create([
        'name' => 'Allowed Person',
        'email' => 'person@example.com',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
    ]);

    expect($user->exists)->toBeTrue();
});

test('a blocked address cannot sign in either', function (): void {
    [$application, $environment] = accessEnvironment('access-signin-block', [
        'access_mode' => AccessMode::Open->value,
    ]);

    $user = User::factory()->create([
        'email' => 'member@example.com',
        'password' => Hash::make('member-password'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);
    $application->users('development')->attach($user, ['role' => 'member', 'environment' => 'development']);

    // The address is fine to begin with.
    bindAccessEnvironment($environment);
    expect(app(ApplicationAccessPolicy::class)->allows($environment, 'member@example.com'))->toBeTrue();

    // Once the domain is blocked, sign-in is refused for that address.
    $environment->update(['settings' => [...$environment->settings, 'access_rules' => [
        ['type' => 'block', 'kind' => 'domain', 'value' => 'example.com'],
    ]]]);

    expect(app(ApplicationAccessPolicy::class)->allows($environment->refresh(), 'member@example.com'))->toBeFalse();
});

test('the authentication page configures access and round trips the rules', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    [$application, $environment] = accessEnvironment('access-settings');

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

    // A freshly provisioned development environment allows sign-ups, so the
    // derived mode is Open until an administrator chooses otherwise.
    $page()->assertSet('data.access_mode', AccessMode::Open->value)
        ->assertSet('data.block_email_subaddresses', false)
        ->assertSet('data.block_disposable_emails', false);

    $page()->set('data.access_mode', AccessMode::Open->value)
        ->set('data.block_email_subaddresses', true)
        ->set('data.block_disposable_emails', true)
        ->set('data.access_rules', [
            ['type' => 'block', 'kind' => 'domain', 'value' => 'Spam.Example'],
            ['type' => 'allow', 'kind' => 'email', 'value' => 'VIP@Example.com'],
        ])
        ->call('save', false)
        ->assertHasNoErrors();

    $environment = $environment->refresh();

    expect($environment->accessMode())->toBe(AccessMode::Open);
    expect($environment->allowsPublicSignup())->toBeTrue();
    expect($environment->blocksEmailSubaddresses())->toBeTrue();
    expect($environment->blocksDisposableEmails())->toBeTrue();

    // Rules are normalised to lower case, and the legacy flag agrees with the mode.
    expect($environment->accessRules())->toBe([
        ['type' => 'block', 'kind' => 'domain', 'value' => 'spam.example'],
        ['type' => 'allow', 'kind' => 'email', 'value' => 'vip@example.com'],
    ]);
    expect($environment->getSetting('allow_signups'))->toBeTrue();

    // Invite-only clears the legacy flag again.
    $page()->set('data.access_mode', AccessMode::InviteOnly->value)
        ->set('data.access_rules', [])
        ->call('save', false)
        ->assertHasNoErrors();

    expect($environment->refresh()->getSetting('allow_signups'))->toBeNull();
    expect($environment->refresh()->accessRules())->toBe([]);
});

test('invalid access rules are rejected', function (array $rules): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    [$application] = accessEnvironment('access-invalid');

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    test()->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($application->tenant);
    Filament::bootCurrentPanel();

    Livewire::actingAs($admin)->test(
        AuthenticationTenantApplication::class,
        ['record' => $application->getKey()],
    )->set('data.access_rules', $rules)
        ->call('save', false)
        ->assertHasErrors();
})->with([
    'invalid email' => [[['type' => 'block', 'kind' => 'email', 'value' => 'not-an-email']]],
    'invalid domain' => [[['type' => 'block', 'kind' => 'domain', 'value' => 'not a domain']]],
    'invalid type' => [[['type' => 'deny', 'kind' => 'domain', 'value' => 'example.com']]],
    'duplicate rule' => [[
        ['type' => 'block', 'kind' => 'domain', 'value' => 'example.com'],
        ['type' => 'block', 'kind' => 'domain', 'value' => 'Example.com'],
    ]],
]);
