<?php

use App\Enums\AccessMode;
use App\Enums\InvitationStatus;
use App\Filament\Resources\TenantApplications\Pages\ListApplicationUsers;
use App\Filament\Resources\TenantApplications\TenantApplicationResource;
use App\Models\ApplicationInvitation;
use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Notifications\ApplicationInvitationNotification;
use App\Services\Auth\ApplicationInvitationService;
use App\Services\OAuth\ApplicationProvisioner;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * @return array{0: Tenant, 1: TenantApplication, 2: TenantApplicationEnvironment}
 */
function invitationContext(string $slug, array $settings = []): array
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

    return [$tenant, $application, $environment->refresh()];
}

/**
 * Read the token that was emailed, so a test can redeem it.
 */
function latestInvitationToken(): string
{
    $token = null;

    Notification::assertSentOnDemand(ApplicationInvitationNotification::class, function (ApplicationInvitationNotification $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    if (! is_string($token)) {
        throw new LogicException('No invitation was sent.');
    }

    return $token;
}

function invitationAdmin(Tenant $tenant): User
{
    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    test()->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($tenant);
    Filament::bootCurrentPanel();

    return $admin;
}

test('inviting an address stores only a hash and emails a usable link', function (): void {
    Notification::fake();
    [, , $environment] = invitationContext('invite-create');

    $invitation = app(ApplicationInvitationService::class)->invite($environment, 'Invitee@Example.com', 'editor');

    // The address is normalised and the plaintext token is never stored.
    expect($invitation->email)->toBe('invitee@example.com');
    expect($invitation->role)->toBe('editor');
    expect($invitation->status())->toBe(InvitationStatus::Pending);

    $token = latestInvitationToken();
    expect($invitation->token_hash)->toBe(ApplicationInvitation::hashToken($token));
    expect($invitation->token_hash)->not->toBe($token);

    // The emailed link resolves back to the invitation.
    expect(app(ApplicationInvitationService::class)->find($token)?->is($invitation))->toBeTrue();
});

test('inviting the same address again retires the previous link', function (): void {
    Notification::fake();
    [, , $environment] = invitationContext('invite-replace');
    $service = app(ApplicationInvitationService::class);

    $first = $service->invite($environment, 'person@example.com');
    $firstToken = latestInvitationToken();

    $second = $service->invite($environment, 'person@example.com');

    expect($service->find($firstToken))->toBeNull();
    expect($first->refresh()->status())->toBe(InvitationStatus::Revoked);
    expect($second->status())->toBe(InvitationStatus::Pending);
    expect($service->find(latestInvitationToken())?->is($second))->toBeTrue();
});

test('an unknown, revoked, accepted, or expired token cannot be redeemed', function (): void {
    Notification::fake();
    [, , $environment] = invitationContext('invite-invalid');
    $service = app(ApplicationInvitationService::class);

    expect($service->find('not-a-real-token'))->toBeNull();
    expect($service->find(''))->toBeNull();

    $revoked = $service->invite($environment, 'revoked@example.com');
    $revokedToken = latestInvitationToken();
    $revoked->revoke();
    expect($service->find($revokedToken))->toBeNull();

    $expired = $service->invite($environment, 'expired@example.com');
    $expiredToken = latestInvitationToken();
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    expect($service->find($expiredToken))->toBeNull();
    expect($expired->refresh()->status())->toBe(InvitationStatus::Expired);
});

test('resending issues a new token and invalidates the old one', function (): void {
    Notification::fake();
    [, , $environment] = invitationContext('invite-resend');
    $service = app(ApplicationInvitationService::class);

    $invitation = $service->invite($environment, 'person@example.com');
    $originalToken = latestInvitationToken();

    $service->resend($invitation);
    $newToken = latestInvitationToken();

    expect($newToken)->not->toBe($originalToken);
    expect($service->find($originalToken))->toBeNull();
    expect($service->find($newToken)?->is($invitation))->toBeTrue();
});

test('an invited address can join an invite-only application', function (): void {
    Notification::fake();
    [, $application, $environment] = invitationContext('invite-only-join', [
        'access_mode' => AccessMode::InviteOnly->value,
    ]);

    // Self-service sign-up is closed, which is the point of the invitation.
    expect($environment->allowsPublicSignup())->toBeFalse();

    $user = User::factory()->create([
        'email' => 'invitee@example.com',
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $invitation = app(ApplicationInvitationService::class)->invite($environment, 'invitee@example.com', 'admin');
    $token = latestInvitationToken();

    $this->actingAs($user)
        ->post(route('invitations.accept', $token))
        ->assertRedirect();

    // The user is enrolled with the invited role, and the invitation is spent.
    expect($application->users('development')->whereKey($user->getKey())->exists())->toBeTrue();
    expect($application->users('development')->whereKey($user->getKey())->sole()->pivot->role)->toBe('admin');
    expect($invitation->refresh()->status())->toBe(InvitationStatus::Accepted);
    expect($invitation->accepted_by)->toBe($user->getKey());

    // The link cannot be used a second time.
    expect(app(ApplicationInvitationService::class)->find($token))->toBeNull();
});

test('an invitation cannot be accepted by a different account', function (): void {
    Notification::fake();
    [, $application, $environment] = invitationContext('invite-mismatch');

    app(ApplicationInvitationService::class)->invite($environment, 'invited@example.com');
    $token = latestInvitationToken();

    $other = User::factory()->create([
        'email' => 'someone-else@example.com',
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $this->actingAs($other)
        ->post(route('invitations.accept', $token))
        ->assertSessionHasErrors('invitation');

    expect($application->users('development')->whereKey($other->getKey())->exists())->toBeFalse();
    expect(ApplicationInvitation::query()->sole()->status())->toBe(InvitationStatus::Pending);
});

test('accepting while signed out sends the visitor to sign in first', function (): void {
    Notification::fake();
    [, , $environment] = invitationContext('invite-signed-out');

    app(ApplicationInvitationService::class)->invite($environment, 'invitee@example.com');
    $token = latestInvitationToken();

    $this->post(route('invitations.accept', $token))
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', route('invitations.show', $token));
});

test('an invited person without an account can create one from the invitation', function (): void {
    Notification::fake();
    [, $application, $environment] = invitationContext('invite-register', [
        'access_mode' => AccessMode::InviteOnly->value,
    ]);

    app(ApplicationInvitationService::class)->invite($environment, 'newcomer@example.com', 'editor');
    $token = latestInvitationToken();

    $this->post(route('invitations.register', $token), [
        'name' => 'New Comer',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
    ])->assertRedirect();

    $user = User::query()->where('email', 'newcomer@example.com')->sole();

    // The account is created in the application's environment and treated as
    // verified, because the invitation was delivered to that address.
    expect($user->identity_namespace)->toBe($environment->identityNamespace());
    expect($user->tenant_application_environment_id)->toBe($environment->getKey());
    expect($user->hasVerifiedEmail())->toBeTrue();
    expect(Hash::check('Password-1234!', $user->password))->toBeTrue();

    expect($application->users('development')->whereKey($user->getKey())->sole()->pivot->role)->toBe('editor');
    expect(ApplicationInvitation::query()->sole()->status())->toBe(InvitationStatus::Accepted);

    // The new account is signed in.
    $this->assertAuthenticatedAs($user);
});

test('registration from an invitation is refused when the address already has an account', function (): void {
    Notification::fake();
    [, , $environment] = invitationContext('invite-register-existing');

    User::factory()->create([
        'email' => 'existing@example.com',
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    app(ApplicationInvitationService::class)->invite($environment, 'existing@example.com');
    $token = latestInvitationToken();

    $this->post(route('invitations.register', $token), [
        'name' => 'Duplicate',
        'password' => 'Password-1234!',
        'password_confirmation' => 'Password-1234!',
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'existing@example.com')->count())->toBe(1);
});

test('an invalid token shows the unavailable state rather than a form', function (): void {
    $this->get(route('invitations.show', 'not-a-real-token'))
        ->assertOk()
        ->assertSee('invalid');
});

test('the invitation page describes the invitation and the visitor', function (): void {
    Notification::fake();
    [, , $environment] = invitationContext('invite-page');

    app(ApplicationInvitationService::class)->invite($environment, 'invitee@example.com');
    $token = latestInvitationToken();

    $response = $this->get(route('invitations.show', $token))->assertOk();

    $response->assertSee('invitee@example.com');
    $response->assertSee('valid');

    // Signed in as the invited address: the page offers a one-click accept.
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $this->actingAs($invitee)
        ->get(route('invitations.show', $token))
        ->assertOk()
        ->assertSee('true');
});

test('an administrator can invite, resend, and revoke from the users page', function (): void {
    Notification::fake();
    [$tenant, $application, $environment] = invitationContext('invite-admin');
    $admin = invitationAdmin($tenant);

    Livewire::actingAs($admin)
        ->test(ListApplicationUsers::class, ['record' => $application->getKey()])
        ->assertOk()
        ->callAction('invite', ['email' => 'invited@example.com', 'role' => 'admin'])
        ->assertHasNoErrors();

    $invitation = ApplicationInvitation::query()->sole();
    expect($invitation->email)->toBe('invited@example.com');
    expect($invitation->role)->toBe('admin');
    expect($invitation->invited_by)->toBe($admin->getKey());

    $originalToken = latestInvitationToken();

    // The pending list surfaces it, and resending replaces the link.
    $page = fn () => Livewire::actingAs($admin)->test(ListApplicationUsers::class, ['record' => $application->getKey()]);

    expect($page()->instance()->pendingInvitations())->toHaveCount(1);

    $page()->call('resendInvitation', $invitation->getKey())->assertHasNoErrors();
    expect(app(ApplicationInvitationService::class)->find($originalToken))->toBeNull();
    expect(app(ApplicationInvitationService::class)->find(latestInvitationToken()))->not->toBeNull();

    $page()->call('revokeInvitation', $invitation->getKey())->assertHasNoErrors();
    expect($invitation->refresh()->status())->toBe(InvitationStatus::Revoked);
    expect($page()->instance()->pendingInvitations())->toHaveCount(0);
});

test('the users page lists pending invitations and hides spent ones', function (): void {
    Notification::fake();
    [$tenant, $application, $environment] = invitationContext('invite-list');
    $admin = invitationAdmin($tenant);

    $pending = app(ApplicationInvitationService::class)->invite($environment, 'waiting@example.com');
    $revoked = app(ApplicationInvitationService::class)->invite($environment, 'cancelled@example.com');
    $revoked->revoke();

    $response = $this->get(TenantApplicationResource::getUrl('users', [
        'tenant' => $tenant,
        'record' => $application,
    ]))->assertOk();

    $response->assertSee('Pending invitations')
        ->assertSee('waiting@example.com')
        ->assertDontSee('cancelled@example.com');

    expect($pending->status())->toBe(InvitationStatus::Pending);
});

test('an invitation is scoped to its own environment', function (): void {
    Notification::fake();
    [, , $environment] = invitationContext('invite-scope-a');
    [, $otherApplication, $otherEnvironment] = invitationContext('invite-scope-b');

    app(ApplicationInvitationService::class)->invite($environment, 'person@example.com');
    $token = latestInvitationToken();

    $user = User::factory()->create([
        'email' => 'person@example.com',
        'identity_namespace' => $otherEnvironment->identityNamespace(),
        'tenant_application_environment_id' => $otherEnvironment->getKey(),
    ]);

    // Accepting enrolls into the inviting environment, not the other one.
    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect();

    expect($environment->application->users('development')->whereKey($user->getKey())->exists())->toBeTrue();
    expect($otherApplication->users('development')->whereKey($user->getKey())->exists())->toBeFalse();
});

test('accepting an unknown invitation is refused', function (): void {
    [, , $environment] = invitationContext('invite-accept-unknown');

    $user = User::factory()->create([
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $this->actingAs($user)
        ->post(route('invitations.accept', 'not-a-real-token'))
        ->assertSessionHasErrors('invitation');
});
