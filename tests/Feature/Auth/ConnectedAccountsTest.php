<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Settings\SocialLoginSettings;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function (): void {
    $this->artisan('migrate', ['--path' => 'database/settings', '--no-interaction' => true]);
    app()->forgetInstance(SocialLoginSettings::class);

    config()->set('services.github.client_id', 'test-id');
    config()->set('services.github.client_secret', 'test-secret');
});

function connectedAccountsGithub(): void
{
    $settings = app(SocialLoginSettings::class);
    $settings->github_enabled = true;
    $settings->github_client_id = 'test-id';
    $settings->github_client_secret = 'test-secret';
    $settings->save();
    app()->forgetInstance(SocialLoginSettings::class);
}

function connectedAccountsSocialiteUser(string $id, string $email): SocialiteUser
{
    $user = (new SocialiteUser)->map([
        'id' => $id,
        'nickname' => 'octocat',
        'name' => 'Octo Cat',
        'email' => $email,
        'avatar' => 'https://avatars.githubusercontent.com/u/'.$id,
    ]);
    $user->setToken('fake-token');

    return $user;
}

test('linking a provider attaches it to the signed-in account', function (): void {
    connectedAccountsGithub();
    $user = User::factory()->create();

    Socialite::fake('github', connectedAccountsSocialiteUser('gh-1', 'octo@example.com'));

    test()->actingAs($user)
        ->get(route('auth.social.redirect', ['provider' => 'github', 'link' => 1]))
        ->assertRedirect();

    test()->actingAs($user)
        ->get(route('auth.social.callback', ['provider' => 'github']))
        ->assertRedirect(route('security.edit'));

    // Linked, not signed in as someone else.
    $this->assertAuthenticatedAs($user);
    $account = SocialAccount::query()->where('provider', 'github')->sole();
    expect((int) $account->user_id)->toBe((int) $user->getKey());
});

test('linking refuses a social identity claimed by another user', function (): void {
    connectedAccountsGithub();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    SocialAccount::query()->create([
        'user_id' => $owner->getKey(),
        'provider' => 'github',
        'provider_id' => 'gh-2',
        'nickname' => 'octocat',
    ]);

    Socialite::fake('github', connectedAccountsSocialiteUser('gh-2', 'octo@example.com'));

    test()->actingAs($other)
        ->get(route('auth.social.redirect', ['provider' => 'github', 'link' => 1]))
        ->assertRedirect();

    test()->actingAs($other)
        ->get(route('auth.social.callback', ['provider' => 'github']))
        ->assertRedirect(route('security.edit'));

    $this->assertAuthenticatedAs($other);
    expect(SocialAccount::query()->where('provider_id', 'gh-2')->sole()->user_id)->toBe($owner->getKey());
});

test('disconnecting removes the account and keeps the session', function (): void {
    $user = User::factory()->create();
    $account = SocialAccount::query()->create([
        'user_id' => $user->getKey(),
        'provider' => 'github',
        'provider_id' => 'gh-3',
        'nickname' => 'octocat',
    ]);

    test()->actingAs($user)
        ->delete(route('security.connections.destroy', ['connection' => $account->getKey()]))
        ->assertRedirect(route('security.edit'));

    $this->assertAuthenticated();
    expect(SocialAccount::query()->whereKey($account->getKey())->exists())->toBeFalse();
});

test('the last way to sign in cannot be disconnected', function (): void {
    // A social-only user: no password, no passkeys, one connection.
    $user = User::factory()->create(['password' => null]);
    $account = SocialAccount::query()->create([
        'user_id' => $user->getKey(),
        'provider' => 'github',
        'provider_id' => 'gh-4',
        'nickname' => 'octocat',
    ]);

    test()->actingAs($user)
        ->delete(route('security.connections.destroy', ['connection' => $account->getKey()]))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(SocialAccount::query()->whereKey($account->getKey())->exists())->toBeTrue();

    // With a password set, the same account disconnects.
    $user->forceFill(['password' => bcrypt('Password-1234!')])->save();

    test()->actingAs($user)
        ->delete(route('security.connections.destroy', ['connection' => $account->getKey()]))
        ->assertRedirect(route('security.edit'));

    expect(SocialAccount::query()->whereKey($account->getKey())->exists())->toBeFalse();
});

test('another accounts connection cannot be disconnected', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = SocialAccount::query()->create([
        'user_id' => $other->getKey(),
        'provider' => 'github',
        'provider_id' => 'gh-5',
        'nickname' => 'octocat',
    ]);

    test()->actingAs($user)
        ->delete(route('security.connections.destroy', ['connection' => $account->getKey()]))
        ->assertNotFound();

    expect(SocialAccount::query()->whereKey($account->getKey())->exists())->toBeTrue();
});

test('the security page lists connected accounts', function (): void {
    $user = User::factory()->create();
    SocialAccount::query()->create([
        'user_id' => $user->getKey(),
        'provider' => 'github',
        'provider_id' => 'gh-6',
        'nickname' => 'octocat',
    ]);

    test()->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn ($assert) => $assert
            ->has('connectedAccounts', 1)
            ->where('connectedAccounts.0.provider', 'github')
            ->where('connectedAccounts.0.nickname', 'octocat')
        );
});
