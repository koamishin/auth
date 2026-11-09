<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Services\OAuth\ApplicationAuthentication;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirms a sign-out requested from a new-device alert email.
 *
 * The signed link in the email is the authority: it revokes exactly the
 * session it names and never the session of whoever clicked it.
 */
class SigninAlertController extends Controller
{
    public function revoke(Request $request, string $user, string $session): Response
    {
        Session::query()->whereKey($session)->where('user_id', $user)->delete();

        return Inertia::render('auth/UnauthorizedSignIn', [
            'revoked' => true,
            'application' => app(ApplicationAuthentication::class)->pageContext($request),
        ])->toResponse($request);
    }
}
