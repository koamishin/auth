<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use Defuse\Crypto\Key;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ResponseInterface;

class CapturedTokenResponse implements ResponseTypeInterface
{
    public ?AccessTokenEntityInterface $accessToken = null;

    public function __construct(private readonly ResponseTypeInterface $response) {}

    public function setAccessToken(AccessTokenEntityInterface $accessToken): void
    {
        $this->accessToken = $accessToken;
        $this->response->setAccessToken($accessToken);
    }

    public function setRefreshToken(RefreshTokenEntityInterface $refreshToken): void
    {
        $this->response->setRefreshToken($refreshToken);
    }

    public function setEncryptionKey(Key|string|null $key = null): void
    {
        $this->response->setEncryptionKey($key);
    }

    public function generateHttpResponse(ResponseInterface $response): ResponseInterface
    {
        return $this->response->generateHttpResponse($response);
    }
}
