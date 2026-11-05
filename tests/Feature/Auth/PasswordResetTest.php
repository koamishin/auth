<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('reset password link screen can be rendered', function (): void {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification): true {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): true {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('password cannot be reset with invalid token', function (): void {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});

test('password reset isolates identical emails between portal and application identities', function (): void {
    Notification::fake();

    $tenant = Tenant::query()->create(['name' => 'Reset Org', 'slug' => 'reset-org', 'is_active' => true]);
    $application = $tenant->applications()->create([
        'name' => 'Reset App',
        'framework' => 'laravel',
        'environment' => 'development',
        'redirect_uris' => [],
    ]);
    $environment = $application->environments()->create([
        'environment' => 'development',
        'redirect_uris' => [],
        'settings' => [],
    ]);

    $sharedEmail = 'shared@reset.test';
    $portalUser = User::factory()->create([
        'email' => $sharedEmail,
        'password' => Hash::make('portal-original'),
        'identity_namespace' => User::PORTAL_IDENTITY_NAMESPACE,
    ]);

    $appUser = User::factory()->create([
        'email' => $sharedEmail,
        'password' => Hash::make('app-original'),
        'identity_namespace' => $environment->identityNamespace(),
        'tenant_application_environment_id' => $environment->getKey(),
    ]);

    $this->post(route('password.email'), ['email' => $sharedEmail]);

    Notification::assertSentTo($portalUser, ResetPassword::class, function ($notification) use ($portalUser, $appUser, $sharedEmail): true {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $sharedEmail,
            'password' => 'new-portal-pass',
            'password_confirmation' => 'new-portal-pass',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('login'));

        expect(Hash::check('new-portal-pass', $portalUser->fresh()->password))->toBeTrue();
        expect(Hash::check('app-original', $appUser->fresh()->password))->toBeTrue();

        return true;
    });
});
