<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('fortify two factor challenge routes are registered', function (): void {
    expect(Route::has('two-factor.login'))->toBeTrue();
    expect(Route::has('two-factor.login.store'))->toBeTrue();
});

test('users completing two factor challenge are redirected to filament admin', function (): void {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->withSession(['login.id' => $user->id])
        ->post(route('two-factor.login.store'), ['recovery_code' => 'code1']);

    $this->assertAuthenticated();
    $response->assertRedirect('/admin');
});

test('users completing two factor challenge via inertia are redirected to filament admin', function (): void {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->withSession(['login.id' => $user->id])
        ->withHeader('X-Inertia', 'true')
        ->post(route('two-factor.login.store'), ['recovery_code' => 'code1']);

    $this->assertAuthenticated();
    expect($response->status())->toBe(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe(url('/admin'));
});
