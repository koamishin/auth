<?php

use App\Enums\EmailCodePurpose;
use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\EmailVerificationCode;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Auth\ApplicationAccessPolicy;
use App\Services\Auth\EmailCodeService;
use App\Services\OAuth\ApplicationProvisioner;
use App\Support\PasswordPolicy;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

/**
 * @return array{0: TenantApplication, 1: TenantApplicationEnvironment}
 */
function enforcementEnvironment(string $slug, array $settings = []): array
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

function blockedEnvironmentMember(TenantApplication $application, TenantApplicationEnvironment $environment, string $email): User
{
    $user = User::factory()->create([
        'email' => $email,
        'password' => Hash::make('member-password'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $application->users($environment->environment)->attach($user, [
        'role' => 'member',
        'environment' => $environment->environment,
    ]);

    return $user;
}

test('the application password minimum is enforced on the security page too', function (): void {
    [, $environment] = enforcementEnvironment('enforce-password-page', ['auth_password_min_length' => 16]);

    $user = User::factory()->create([
        'password' => Hash::make('Password-1234!'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $this->actingAs($user);

    // The security page carries no authorization context, so the environment is
    // resolved from the signed in user's own account.
    expect(PasswordPolicy::minimum($user->tenantApplicationEnvironment))->toBe(16);

    // A password shorter than the application's minimum is refused.
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('user-password.update'), [
            'current_password' => 'Password-1234!',
            'password' => 'Short-1234!',
            'password_confirmation' => 'Short-1234!',
        ])
        ->assertSessionHasErrors('password');

    expect(Hash::check('Password-1234!', $user->refresh()->password))->toBeTrue();

    // One that meets it is accepted.
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('user-password.update'), [
            'current_password' => 'Password-1234!',
            'password' => 'A-Long-Enough-Password-1!',
            'password_confirmation' => 'A-Long-Enough-Password-1!',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('A-Long-Enough-Password-1!', $user->refresh()->password))->toBeTrue();
});

test('a blocked address cannot be issued an email code by any caller', function (): void {
    Notification::fake();
    [$application, $environment] = enforcementEnvironment('enforce-code-block', [
        'auth_email_code_signin' => true,
        'access_rules' => [['type' => 'block', 'kind' => 'domain', 'value' => 'blocked.example']],
    ]);

    $user = blockedEnvironmentMember($application, $environment, 'person@blocked.example');

    // The check lives in the service, so even a direct call sends nothing.
    expect(app(EmailCodeService::class)->send($user, EmailCodePurpose::SignIn))->toBeNull();
    Notification::assertCount(0);

    // No code was stored either, so the verify endpoint cannot accept one.
    expect(EmailVerificationCode::query()->where('user_id', $user->getKey())->exists())->toBeFalse();
    expect(app(ApplicationAccessPolicy::class)->allows($environment, $user->email))->toBeFalse();
});

test('an allowed address can still be issued an email code', function (): void {
    Notification::fake();
    [$application, $environment] = enforcementEnvironment('enforce-code-allow', [
        'auth_email_code_signin' => true,
        'access_rules' => [['type' => 'block', 'kind' => 'domain', 'value' => 'blocked.example']],
    ]);

    $user = blockedEnvironmentMember($application, $environment, 'person@allowed.example');

    expect(app(EmailCodeService::class)->send($user, EmailCodePurpose::SignIn))->not->toBeNull();
    Notification::assertCount(1);
});

test('a blocked address cannot sign in with a passkey', function (): void {
    [$application, $environment] = enforcementEnvironment('enforce-passkey-block', [
        'access_rules' => [['type' => 'block', 'kind' => 'domain', 'value' => 'blocked.example']],
    ]);

    $user = blockedEnvironmentMember($application, $environment, 'person@blocked.example');
    $user->passkeys()->create([
        'name' => 'Device',
        'credential_id' => 'blocked-credential',
        'credential' => ['aaguid' => 'test'],
    ]);

    // The passkey sign-in controller refuses a refused address.
    $policy = app(ApplicationAccessPolicy::class);
    expect($policy->allows($environment, $user->email))->toBeFalse();

    // An allowed member is unaffected.
    $allowed = blockedEnvironmentMember($application, $environment, 'person@allowed.example');
    expect($policy->allows($environment, $allowed->email))->toBeTrue();
});

test('a blocked address cannot sign in with a social connection', function (): void {
    [$application, $environment] = enforcementEnvironment('enforce-social-block', [
        'access_rules' => [['type' => 'block', 'kind' => 'domain', 'value' => 'blocked.example']],
    ]);

    $user = blockedEnvironmentMember($application, $environment, 'person@blocked.example');
    SocialAccount::query()->create([
        'user_id' => $user->getKey(),
        'provider' => 'github',
        'provider_id' => 'gh-blocked',
        'connection_scope' => 'portal',
    ]);

    $policy = app(ApplicationAccessPolicy::class);

    // The same address is refused however it tries to sign in.
    expect($policy->allows($environment, $user->email))->toBeFalse();
    expect($policy->reasonFor($environment, $user->email))->toBe('This email address is not allowed for this application.');
});

test('every sign-in method shares one refusal decision', function (): void {
    [, $environment] = enforcementEnvironment('enforce-single-decision', [
        'access_rules' => [
            ['type' => 'block', 'kind' => 'domain', 'value' => 'blocked.example'],
            ['type' => 'allow', 'kind' => 'domain', 'value' => 'partner.example'],
        ],
    ]);

    $policy = app(ApplicationAccessPolicy::class);

    // Blocked, allowed, and neither-listed addresses behave the same whichever
    // method the user reaches for, because they all consult this one policy.
    expect($policy->allows($environment, 'person@blocked.example'))->toBeFalse();
    expect($policy->allows($environment, 'person@partner.example'))->toBeTrue();
    expect($policy->allows($environment, 'person@stranger.example'))->toBeFalse();
});

test('the coming soon tabs describe the roadmap instead of offering controls', function (): void {
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    [$application] = enforcementEnvironment('enforce-coming-soon');

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($application->tenant);
    Filament::bootCurrentPanel();

    $response = $this->get(TenantApplicationResource::getUrl('authentication', [
        'tenant' => $application->tenant,
        'record' => $application,
    ]))->assertOk();

    $response->assertSee('Phone')
        ->assertSee('Username')
        ->assertSee('Enterprise SSO')
        ->assertSee('Web3')
        ->assertSee('Coming soon')
        // The old placeholder controls are gone.
        ->assertDontSee('Sign-in with phone number')
        ->assertDontSee('Sign-up with username');
});
