<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;

final class OwnedResourceServer extends ResourceServer
{
    public function __construct(
        AccessTokenRepositoryInterface $tokens,
        CryptKeyInterface $publicKey,
        private readonly GrantFamilies $families,
    ) {
        parent::__construct($tokens, $publicKey);
    }

    public function validateAuthenticatedRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $this->families->connection();

        return parent::validateAuthenticatedRequest($request);
    }
}
