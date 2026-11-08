<?php

use App\Models\Session;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('the security page lists the account sessions', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Session::query()->create([
        'id' => 'devicealpha',
        'user_id' => $user->getKey(),
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        'payload' => '',
        'last_activity' => time() - 60,
    ]);
    Session::query()->create([
        'id' => 'devicebeta',
        'user_id' => $user->getKey(),
        'ip_address' => '10.0.0.2',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'payload' => '',
        'last_activity' => time(),
    ]);
    Session::query()->create([
        'id' => 'someoneelsesdevice',
        'user_id' => $other->getKey(),
        'ip_address' => '10.0.0.3',
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)',
        'payload' => '',
        'last_activity' => time(),
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $assert): AssertableInertia => $assert
            ->has('devices', 2)
            ->where('devices.0.id', 'devicebeta')
            ->where('devices.0.ip', '10.0.0.2')
            ->where('devices.0.current', false)
            ->where('devices.1.id', 'devicealpha')
        );
});

test('revoking another device signs that device out and keeps the session', function (): void {
    $user = User::factory()->create();
    Session::query()->create([
        'id' => 'devicealpha',
        'user_id' => $user->getKey(),
        'payload' => '',
        'last_activity' => time(),
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('security.devices.destroy', ['session' => 'devicealpha']))
        ->assertRedirect(route('security.edit'));

    $this->assertAuthenticated();
    expect(Session::query()->whereKey('devicealpha')->exists())->toBeFalse();
});

test('revoking the current device signs the user out', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    // The request above established this session; the device row matches it.
    $current = (string) session()->getId();
    Session::query()->create([
        'id' => $current,
        'user_id' => $user->getKey(),
        'payload' => '',
        'last_activity' => time(),
    ]);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('security.devices.destroy', ['session' => $current]))
        ->assertRedirect('/');

    $this->assertGuest();
    expect(Session::query()->whereKey($current)->exists())->toBeFalse();
});

test('signing out all other devices keeps the current one', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $current = (string) session()->getId();
    Session::query()->create(['id' => $current, 'user_id' => $user->getKey(), 'payload' => '', 'last_activity' => time()]);
    Session::query()->create(['id' => 'otherdevice', 'user_id' => $user->getKey(), 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($user)
        ->withCookie(config('session.cookie'), $current)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('security.devices.destroy-others'))
        ->assertRedirect(route('security.edit'));

    $this->assertAuthenticated();
    expect(Session::query()->whereKey('otherdevice')->exists())->toBeFalse();
    expect(Session::query()->whereKey($current)->exists())->toBeTrue();
});

test('another accounts session cannot be revoked', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Session::query()->create([
        'id' => 'notmine',
        'user_id' => $other->getKey(),
        'payload' => '',
        'last_activity' => time(),
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('security.devices.destroy', ['session' => 'notmine']))
        ->assertNotFound();

    expect(Session::query()->whereKey('notmine')->exists())->toBeTrue();
});
