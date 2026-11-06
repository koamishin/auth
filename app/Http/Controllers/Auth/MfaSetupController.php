<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\MfaSetupViewResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The standalone screen an application sends a user to when a second factor is
 * required and they have not enrolled one yet.
 */
class MfaSetupController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return app(MfaSetupViewResponse::class)->toResponse($request);
    }
}
