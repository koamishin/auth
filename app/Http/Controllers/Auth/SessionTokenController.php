<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\SessionTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;

/**
 * Mints short-lived session tokens for the current session or access token.
 *
 * The token itself is the response; backends verify it offline against the
 * JWKS document, so minting is the only part that touches the portal.
 */
class SessionTokenController extends Controller
{
    public function __construct(private SessionTokenService $tokens) {}

    /**
     * Mint a token for the browser session's signed-in user.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->tokens->mintForBrowserSession($request));
    }

    /**
     * Mint a token from a user's OAuth access token, for SDK-driven backends.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = method_exists($user, 'token') ? $user->token() : null;

        abort_unless($token instanceof AccessToken, 403, 'Only user tokens can mint session tokens.');

        return response()->json($this->tokens->mintForAccessToken($request, $token));
    }
}
