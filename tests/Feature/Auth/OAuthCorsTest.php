<?php

declare(strict_types=1);

test('cross-origin browser SDK requests receive CORS headers on OAuth and OIDC endpoints', function (string $method, string $uri): void {
    $response = $this->withHeaders([
        'Origin' => 'http://localhost:3000',
        'Access-Control-Request-Method' => $method,
    ])->options($uri);

    $response->assertSuccessful()
        ->assertHeader('Access-Control-Allow-Origin', '*');
})->with([
    'token exchange' => ['POST', '/oauth/token'],
    'jwks document' => ['GET', '/oauth/jwks'],
    'oidc discovery' => ['GET', '/.well-known/openid-configuration'],
    'userinfo' => ['GET', '/api/oauth/userinfo'],
    'session token mint' => ['POST', '/api/oauth/session-token'],
]);
