<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ApplicationInvitation;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Auth\ApplicationInvitationService;
use App\Services\OAuth\ApplicationAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets an invited person join an application: either by signing in to an
 * existing account, or by creating one from the invitation.
 */
class ApplicationInvitationController extends Controller
{
    public function __construct(private readonly ApplicationInvitationService $invitations) {}

    /**
     * Show the invitation, or explain why it cannot be used.
     */
    public function show(Request $request, string $token): Response
    {
        $invitation = $this->invitations->find($token);

        if (! $invitation instanceof ApplicationInvitation) {
            return Inertia::render('auth/Invitation', ['state' => 'invalid']);
        }

        $environment = $invitation->environment;
        $user = $request->user();

        return Inertia::render('auth/Invitation', [
            'state' => 'valid',
            'token' => $token,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'application' => [
                'name' => $environment->application->name,
                'organization' => $environment->application->tenant->name,
                'environment' => $environment->environment,
            ],
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'signedInAs' => $user instanceof User ? $user->email : null,
            'matchesSignedInUser' => $user instanceof User && $invitation->matches($user->email),
            'accountExists' => $this->accountExists($environment, $invitation->email),
            'passwordRules' => Password::defaults()?->toPasswordRulesString(),
        ]);
    }

    /**
     * Accept the invitation as the signed in user.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->find($token);
        $user = $request->user();

        if (! $invitation instanceof ApplicationInvitation) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is no longer valid.']);
        }

        if (! $user instanceof User) {
            return redirect()->route('login')->with('url.intended', route('invitations.show', $token));
        }

        if (! $invitation->matches($user->email)) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation was sent to '.$invitation->email.', so a different account cannot accept it.',
            ]);
        }

        $this->redeem($invitation, $user);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Create an account for the invited address and accept the invitation.
     */
    public function register(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->find($token);

        if (! $invitation instanceof ApplicationInvitation) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is no longer valid.']);
        }

        $environment = $invitation->environment;

        if ($this->accountExists($environment, $invitation->email)) {
            throw ValidationException::withMessages([
                'email' => 'An account already exists for this address. Sign in to accept the invitation.',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($invitation, $environment, $data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => Hash::make($data['password']),
                'identity_namespace' => $environment->identityNamespace(),
                'tenant_application_environment_id' => $environment->getKey(),
            ]);

            // The invited address is treated as verified: it is the address the
            // invitation was delivered to.
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->redeem($invitation, $user);

            return $user;
        });

        Auth::guard(config('fortify.guard', 'web'))->login($user);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function redeem(ApplicationInvitation $invitation, User $user): void
    {
        $environment = $invitation->environment;

        app(ApplicationAuthentication::class)->enrollInvited($environment, $user, $invitation->role);

        $invitation->markAccepted($user);
    }

    private function accountExists(TenantApplicationEnvironment $environment, string $email): bool
    {
        return User::query()
            ->where('identity_namespace', $environment->identityNamespace())
            ->where('email', $email)
            ->exists();
    }
}
