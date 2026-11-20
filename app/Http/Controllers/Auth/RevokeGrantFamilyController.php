<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\OAuth\GrantFamilies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RevokeGrantFamilyController extends Controller
{
    public function __invoke(Request $request, GrantFamilies $families): Response
    {
        $data = $request->validate(['revocation_token' => ['required', 'string', 'max:160']]);
        abort_unless($families->revokeCredential($data['revocation_token']), 401);

        return response()->noContent(204, ['Cache-Control' => 'no-store']);
    }
}
