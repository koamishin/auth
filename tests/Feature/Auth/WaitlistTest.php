<?php

use App\Filament\Resources\TenantApplications\Pages\ListApplicationUsers;
use App\Models\ApplicationInvitation;
use App\Models\ApplicationWaitlistEntry;
use App\Models\Tenant;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use Filament\Facades\Filament;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Client;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * @return array{0: Client, 1: TenantApplicationEnvironment}
 */
function waitlistEnvironment(string $slug, string $mode = 'waitlist'): array
{
    $client = new Client;
    $client->forceFill([
        'name' => $slug,
        'secret' => $slug.'-secret',
        'redirect_uris' => ['https://'.$slug.'.internal/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
    ])->save();

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
        'settings' => ['access_mode' => $mode],
    ]);

    return [$client, $environment->refresh()];
}

function waitlistFlow(Client $client): string
{
    $challenge = rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '=');
    $response = test()->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => $client->getAttribute('redirect_uris')[0],
        'response_type' => 'code',
        'state' => 'waitlist123',
        'scope' => 'openid profile email',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    $flow = $response->viewData('flow');
    expect($flow)->toBeString()->not->toBe('');

    return $flow;
}

test('waitlist applications offer a join form instead of sign-up', function (): void {
    [$client] = waitlistEnvironment('waitlist-join');
    $flow = waitlistFlow($client);

    test()->get('/register?flow='.$flow)
        ->assertOk()
        ->assertInertia(fn (Assert $assert): AssertableInertia => $assert
            ->where('application.waitlist', true)
            ->where('application.allowSignups', false)
            ->where('waitlistJoined', false)
        );
});

test('joining the waitlist records the address once', function (): void {
    [$client, $environment] = waitlistEnvironment('waitlist-record');
    $flow = waitlistFlow($client);

    test()->post(route('auth.waitlist'), ['flow' => $flow, 'email' => 'Fan@Example.test'])
        ->assertRedirect();

    test()->post(route('auth.waitlist'), ['flow' => $flow, 'email' => 'fan@example.test'])
        ->assertRedirect();

    expect(ApplicationWaitlistEntry::query()
        ->where('tenant_application_environment_id', $environment->getKey())
        ->count())->toBe(1);
    expect(ApplicationWaitlistEntry::query()->sole()->email)->toBe('fan@example.test');
});

test('joining the waitlist confirms the request', function (): void {
    [$client] = waitlistEnvironment('waitlist-confirm');
    $flow = waitlistFlow($client);

    test()->post(route('auth.waitlist'), ['flow' => $flow, 'email' => 'fan@example.test'])
        ->assertRedirect();

    test()->get('/register?flow='.$flow)
        ->assertInertia(fn (Assert $assert): AssertableInertia => $assert
            ->where('waitlistJoined', true)
        );
});

test('joining the waitlist needs a valid address', function (): void {
    [$client, $environment] = waitlistEnvironment('waitlist-invalid');
    $flow = waitlistFlow($client);

    test()->post(route('auth.waitlist'), ['flow' => $flow, 'email' => 'not-an-email'])
        ->assertSessionHasErrors('email');

    expect(ApplicationWaitlistEntry::query()->count())->toBe(0);
});

test('applications that accept sign-ups do not take waitlist joins', function (): void {
    [$client] = waitlistEnvironment('waitlist-open', 'open');
    $flow = waitlistFlow($client);

    test()->post(route('auth.waitlist'), ['flow' => $flow, 'email' => 'fan@example.test'])
        ->assertNotFound();

    expect(ApplicationWaitlistEntry::query()->count())->toBe(0);
});

test('operators can invite a waitlisted address', function (): void {
    [, $environment] = waitlistEnvironment('waitlist-invite');
    $entry = ApplicationWaitlistEntry::query()->create([
        'tenant_application_environment_id' => $environment->getKey(),
        'email' => 'fan@example.test',
    ]);

    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    test()->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($environment->application->tenant);
    Filament::bootCurrentPanel();

    Livewire::test(ListApplicationUsers::class, ['record' => $environment->application->getKey()])
        ->call('inviteWaitlisted', $entry->getKey())
        ->assertHasNoErrors();

    expect(ApplicationInvitation::query()
        ->where('tenant_application_environment_id', $environment->getKey())
        ->where('email', 'fan@example.test')
        ->exists())->toBeTrue();
    expect($entry->refresh()->invited_at)->not->toBeNull();
});

test('a stray entry id invites nobody', function (): void {
    [, $environment] = waitlistEnvironment('waitlist-stray');
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    test()->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($environment->application->tenant);
    Filament::bootCurrentPanel();

    Livewire::test(ListApplicationUsers::class, ['record' => $environment->application->getKey()])
        ->call('inviteWaitlisted', 999)
        ->assertHasNoErrors();

    expect(ApplicationInvitation::query()->count())->toBe(0);
});
