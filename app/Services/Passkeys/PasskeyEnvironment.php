<?php

declare(strict_types=1);

namespace App\Services\Passkeys;

use App\Models\TenantApplicationEnvironment;
use Illuminate\Http\Request;

/**
 * Resolves the application environment a passkey ceremony belongs to.
 *
 * Passkey options are built while handling a request, so this reads the request
 * rather than caching anything, which keeps it safe under Octane.
 */
class PasskeyEnvironment
{
    public function __construct(private readonly Request $request) {}

    public function current(): ?TenantApplicationEnvironment
    {
        $environment = $this->request->attributes->get('koamishin.application_environment');

        return $environment instanceof TenantApplicationEnvironment ? $environment : null;
    }
}
