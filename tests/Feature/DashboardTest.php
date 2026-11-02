<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Spatie\Permission\Models\Role;

test('guests are redirected to the login page', function (): void {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('admin users visiting the dashboard are redirected to the admin panel', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $response = $this->actingAs($user)->get(route('dashboard'));
    $response->assertRedirect('/admin');
});

test('admin users visiting the dashboard via inertia are redirected to the admin panel', function (): void {
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    $version = (new HandleInertiaRequests)->version(request());
    $response = $this->actingAs($user)->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
    ])->get(route('dashboard'));
    expect($response->status())->toBe(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe(url('/admin'));
});
