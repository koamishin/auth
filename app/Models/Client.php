<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as BaseClient;

class Client extends BaseClient
{
    /**
     * Determine if the client should skip the authorization prompt.
     *
     * @param  array<int, string>  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        $environment = TenantApplicationEnvironment::query()
            ->where('oauth_client_id', $this->getKey())
            ->first();

        if (! $environment instanceof TenantApplicationEnvironment) {
            return false;
        }

        if ($environment->requiresOAuthConsent()) {
            return false;
        }

        return true;
    }
}
