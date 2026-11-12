<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use DateInterval;
use Defuse\Crypto\Key;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Grant\GrantTypeInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OwnedAuthorizationServer extends AuthorizationServer
{
    /** @param array<array{GrantTypeInterface, DateInterval}> $grants */
    public function __construct(
        ClientRepositoryInterface $clients,
        AccessTokenRepositoryInterface $tokens,
        ScopeRepositoryInterface $scopes,
        CryptKeyInterface $privateKey,
        Key|string $encryptionKey,
        ResponseTypeInterface $response,
        private readonly GrantFamilies $families,
        string $defaultScope,
        array $grants,
    ) {
        parent::__construct($clients, $tokens, $scopes, $privateKey, $encryptionKey, $response);
        $this->setDefaultScope($defaultScope);
        $this->revokeRefreshTokens(true);

        foreach ($grants as [$grant, $ttl]) {
            parent::enableGrantType($grant, $ttl);
        }
    }

    public function enableGrantType(GrantTypeInterface $grantType, ?DateInterval $accessTokenTTL = null): void
    {
        throw new LogicException('Adding OAuth grants to the owned authorization server is unsupported.');
    }

    public function revokeRefreshTokens(bool $revokeRefreshTokens): void
    {
        if (! $revokeRefreshTokens) {
            throw new LogicException('Disabling refresh token rotation is unsupported.');
        }

        parent::revokeRefreshTokens(true);
    }

    public function validateAuthorizationRequest(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        $this->families->connection();

        return parent::validateAuthorizationRequest($request);
    }

    public function completeAuthorizationRequest(AuthorizationRequestInterface $authRequest, ResponseInterface $response): ResponseInterface
    {
        $this->families->connection();

        return parent::completeAuthorizationRequest($authRequest, $response);
    }

    public function respondToAccessTokenRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->families->connection();

        return parent::respondToAccessTokenRequest($request, $response);
    }

    public function respondToDeviceAuthorizationRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->families->connection();

        return parent::respondToDeviceAuthorizationRequest($request, $response);
    }

    public function completeDeviceAuthorizationRequest(string $deviceCode, string $userId, bool $userApproved): void
    {
        $this->families->connection();
        parent::completeDeviceAuthorizationRequest($deviceCode, $userId, $userApproved);
    }
}
