<?php

use App\Models\User;
use App\Support\VerificationDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('a verification link from another device shows the unauthorized sign-in page', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);
    $device = app(VerificationDevice::class);
    $requesting = Request::create('/register', 'POST');
    $fingerprint = $device->fingerprint($requesting, create: true);

    $url = URL::signedRoute('verification.verify', [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
        'device' => $fingerprint,
    ]);

    $this->actingAs($user)
        ->get($url)
        ->assertStatus(403)
        ->assertInertia(fn (Assert $assert): AssertableInertia => $assert
            ->component('auth/UnauthorizedSignIn')
        );

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('the requesting device completes the verification', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);
    $device = app(VerificationDevice::class);
    $requesting = Request::create('/register', 'POST');
    $fingerprint = $device->fingerprint($requesting, create: true);

    $url = URL::signedRoute('verification.verify', [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
        'device' => $fingerprint,
    ]);

    $this->actingAs($user)
        ->withCookie(VerificationDevice::COOKIE, (string) $requesting->cookies->get(VerificationDevice::COOKIE))
        ->get($url)
        ->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('links without a device fingerprint are not held against the device', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);

    $url = URL::signedRoute('verification.verify', [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->actingAs($user)
        ->get($url)
        ->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
