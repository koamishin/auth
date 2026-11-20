<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

class FamilyBearerTokenResponse extends BearerTokenResponse
{
    public function __construct(private readonly GrantFamilies $families, private readonly ApplicationAccess $access) {}

    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        if ($accessToken->getUserIdentifier() === null || !$this->access->environmentForClient($accessToken->getClient()->getIdentifier()) instanceof \App\Models\TenantApplicationEnvironment) {
            return [];
        }
        $credential = $this->families->issueCredential($accessToken->getIdentifier());

        return $credential === null ? [] : ['revocation_token' => $credential];
    }
}
