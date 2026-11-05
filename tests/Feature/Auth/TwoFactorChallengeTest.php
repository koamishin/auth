<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

test('fortify two factor challenge routes are registered', function (): void {
    expect(Route::has('two-factor.login'))->toBeTrue();
    expect(Route::has('two-factor.login.store'))->toBeTrue();
});

test('application users completing two factor challenge are redirected to dashboard', function (): void {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->withSession(['login.id' => $user->id])
        ->post(route('two-factor.login.store'), ['recovery_code' => 'code1']);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('system admins completing two factor challenge are redirected to filament admin', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole('super_admin');

    $response = $this->withSession(['login.id' => $user->id])
        ->post(route('two-factor.login.store'), ['recovery_code' => 'code1']);

    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user, 'portal');
    $response->assertRedirect('/admin');
});

test('system admins completing two factor challenge via inertia are redirected to filament admin', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole('super_admin');

    $response = $this->withSession(['login.id' => $user->id])
        ->withHeader('X-Inertia', 'true')
        ->post(route('two-factor.login.store'), ['recovery_code' => 'code1']);

    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user, 'portal');
    expect($response->status())->toBe(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe(url('/admin'));
});
