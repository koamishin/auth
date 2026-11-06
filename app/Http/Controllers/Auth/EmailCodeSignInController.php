<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\EmailCodePurpose;
use App\Http\Controllers\Controller;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Services\Auth\EmailCodeService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passwordless sign-in with a one-time code emailed to the user.
 *
 * Only available for applications that enable it, and the account must belong to
 * the environment the request came from.
 */
class EmailCodeSignInController extends Controller
{
    public function __construct(private readonly EmailCodeService $codes) {}

    /**
     * Send a sign-in code, revealing nothing about whether the account exists.
     */
    public function send(Request $request): Response
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $environment = $this->environment($request);
        $email = (string) $request->input('email');
        $user = $this->findUser($environment, $email);

        // Always answer the same way, so the endpoint cannot be used to discover
        // which addresses have accounts.
        if ($user instanceof User) {
            $this->codes->send($user, EmailCodePurpose::SignIn);
        }

        return $this->sent();
    }

    /**
     * Check the code and sign the user in.
     */
    public function verify(Request $request): Response
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'digits:'.EmailCodeService::CODE_LENGTH],
        ]);

        $environment = $this->environment($request);
        $user = $this->findUser($environment, (string) $request->input('email'));

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'code' => __('That code is not correct.'),
            ]);
        }

        if ($user->isBanned()) {
            throw ValidationException::withMessages([
                'email' => $user->ban_reason
                    ? __('Your account has been suspended: :reason', ['reason' => $user->ban_reason])
                    : __('Your account has been suspended. Please contact support.'),
            ]);
        }

        $this->codes->verify($user, (string) $request->input('code'), EmailCodePurpose::SignIn);

        /** @var StatefulGuard $guard */
        $guard = Auth::guard(config('fortify.guard', 'web'));
        $guard->login($user, false);

        $request->session()->regenerate();

        // The shared login response keeps application flows moving and applies the
        // same redirects and admin handling as a password sign-in.
        return app(LoginResponse::class)->toResponse($request);
    }

    /**
     * The same response whether or not the address has an account, so this
     * endpoint cannot be used to enumerate users. The frontend advances to the
     * code step optimistically and only surface validation errors.
     */
    private function sent(): Response
    {
        return redirect()->back();
    }

    private function environment(Request $request): TenantApplicationEnvironment
    {
        $environment = $request->attributes->get('koamishin.application_environment');

        // Code sign-in is an application capability, so it is only offered where an
        // application has opted in. Without that context there is nothing to allow it.
        if (! $environment instanceof TenantApplicationEnvironment) {
            throw ValidationException::withMessages([
                'email' => __('Email code sign-in is only available for applications that enable it.'),
            ]);
        }

        if (! $environment->allowsEmailCodeSignin()) {
            throw ValidationException::withMessages([
                'email' => __('This application does not allow signing in with an email code.'),
            ]);
        }

        return $environment;
    }

    private function findUser(TenantApplicationEnvironment $environment, string $email): ?User
    {
        /** @var User|null $user */
        $user = User::query()
            ->where('identity_namespace', $environment->identityNamespace())
            ->where('email', $email)
            ->first();

        return $user;
    }
}
