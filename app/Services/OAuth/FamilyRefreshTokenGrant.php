<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use DateInterval;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class FamilyRefreshTokenGrant extends RefreshTokenGrant
{
    public function __construct(RefreshTokenRepository $refreshTokenRepository, private readonly GrantFamilies $families)
    {
        parent::__construct($refreshTokenRepository);
    }

    public function respondToAccessTokenRequest(ServerRequestInterface $request, ResponseTypeInterface $responseType, DateInterval $accessTokenTTL): ResponseTypeInterface
    {
        $encrypted = $this->getRequestParameter('refresh_token', $request);
        if ($encrypted === null) {
            throw OAuthServerException::invalidRequest('refresh_token');
        }
        try {
            $payload = json_decode($this->decrypt($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw OAuthServerException::invalidRefreshToken();
        }
        $oldAccessId = is_array($payload) ? ($payload['access_token_id'] ?? null) : null;
        if (! is_string($oldAccessId) || ! preg_match('/\A[0-9a-f]{80}\z/', $oldAccessId)) {
            throw OAuthServerException::invalidRefreshToken();
        }

        return $this->families->connection()->transaction(function () use ($request, $responseType, $accessTokenTTL, $oldAccessId): ResponseTypeInterface {
            $family = $this->families->lockForToken($oldAccessId);
            $captured = new CapturedTokenResponse($responseType);
            $response = parent::respondToAccessTokenRequest($request, $captured, $accessTokenTTL);
            if ($captured->accessToken === null) {
                throw OAuthServerException::serverError('No access token was issued.');
            }
            $this->families->attach($family->id, $captured->accessToken->getIdentifier());

            return $response;
        });
    }
}
