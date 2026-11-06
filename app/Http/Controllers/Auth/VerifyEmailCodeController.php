<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\EmailCodePurpose;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\EmailCodeService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\VerifyEmailResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Completes email verification with a one-time code instead of a link.
 */
class VerifyEmailCodeController extends Controller
{
    public function __construct(private readonly EmailCodeService $codes) {}

    /**
     * Check the submitted code, mark the address verified, and continue the flow.
     */
    public function store(Request $request): Response
    {
        $request->validate([
            'code' => ['required', 'string', 'digits:'.EmailCodeService::CODE_LENGTH],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'code' => __('Sign in again to verify your email address.'),
            ]);
        }

        if (! ($user instanceof MustVerifyEmail && $user->hasVerifiedEmail())) {
            $this->codes->verify($user, (string) $request->input('code'), EmailCodePurpose::Verification);

            $user->markEmailAsVerified();

            event(new Verified($user));
        }

        return $this->continue($request);
    }

    /**
     * Send a fresh code to the signed-in user.
     */
    public function resend(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'code' => __('Sign in again to verify your email address.'),
            ]);
        }

        $this->codes->send($user, EmailCodePurpose::Verification);

        return $request->header('X-Inertia')
            ? Inertia::location($request->header('Referer') ?? url()->current())
            : redirect()->back();
    }

    /**
     * Hand the request to the configured verification response so application
     * flows continue exactly as they do after a verification link is followed.
     */
    private function continue(Request $request): Response
    {
        $response = app(VerifyEmailResponse::class)->toResponse($request);

        if ($response instanceof RedirectResponse && $request->header('X-Inertia')) {
            return Inertia::location($response->getTargetUrl());
        }

        return $response;
    }
}
