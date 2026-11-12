<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Services\OAuth\OwnedAuthorizationServerFactory;
use App\Services\OAuth\PassportBridgeClientRepository;
use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Bridge\AccessToken;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Bridge\ScopeRepository;
use Laravel\Passport\Client as PassportClient;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\DeviceCode;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenFactory;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\ResourceServer;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionProperty;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;
use Throwable;

uses(TestCase::class);

class UnexpectedOAuthAccessRepository extends AccessTokenRepository {}

class UnexpectedOAuthRefreshRepository extends RefreshTokenRepository {}

class UnexpectedOAuthToken extends Token {}

class UnexpectedOAuthRefreshToken extends RefreshToken {}

class UnexpectedOAuthClient extends PassportClient {}

class UnexpectedOAuthAuthCode extends AuthCode {}

class UnexpectedOAuthDeviceCode extends DeviceCode {}

class UnexpectedOAuthAccessToken extends AccessToken {}

class UnexpectedOAuthClientRepository extends ClientRepository {}

class OAuthServerConsumer
{
    public function __construct(public AuthorizationServer $server) {}
}

class OAuthResourceServerConsumer
{
    public function __construct(public ResourceServer $server) {}
}

class OAuthPersonalTokenConsumer
{
    public function __construct(public PersonalAccessTokenFactory $factory) {}
}

beforeEach(function (): void {
    $this->originalTokenModel = Passport::$tokenModel;
    $this->originalRefreshTokenModel = Passport::$refreshTokenModel;
    $this->originalRefreshRotation = Passport::$revokeRefreshTokenAfterUse;
    $this->originalPasswordGrant = Passport::$passwordGrantEnabled;
    $this->originalImplicitGrant = Passport::$implicitGrantEnabled;
    $this->originalClientModel = Passport::$clientModel;
    $this->originalAuthCodeModel = Passport::$authCodeModel;
    $this->originalDeviceCodeModel = Passport::$deviceCodeModel;
    $this->originalAccessTokenEntity = Passport::$accessTokenEntity;
});

afterEach(function (): void {
    Passport::$tokenModel = $this->originalTokenModel;
    Passport::$refreshTokenModel = $this->originalRefreshTokenModel;
    Passport::$revokeRefreshTokenAfterUse = $this->originalRefreshRotation;
    Passport::$passwordGrantEnabled = $this->originalPasswordGrant;
    Passport::$implicitGrantEnabled = $this->originalImplicitGrant;
    Passport::$clientModel = $this->originalClientModel;
    Passport::$authCodeModel = $this->originalAuthCodeModel;
    Passport::$deviceCodeModel = $this->originalDeviceCodeModel;
    Passport::$accessTokenEntity = $this->originalAccessTokenEntity;
});

test('custom access or refresh repositories are rejected', function (string $repository, string $replacement): void {
    $this->app->bind($repository, $replacement);
    $this->expectException(LogicException::class);

    $this->app->make(OwnedAuthorizationServerFactory::class)->make();
})->with([
    [AccessTokenRepository::class, UnexpectedOAuthAccessRepository::class],
    [RefreshTokenRepository::class, UnexpectedOAuthRefreshRepository::class],
]);

test('each refresh repository from a factory is checked', function (): void {
    $resolutions = 0;
    $this->app->bind(RefreshTokenRepository::class, function ($app) use (&$resolutions): RefreshTokenRepository {
        $resolutions++;

        return $resolutions === 1
            ? new RefreshTokenRepository($app['events'])
            : new UnexpectedOAuthRefreshRepository($app['events']);
    });
    $this->expectException(LogicException::class);

    $this->app->make(OwnedAuthorizationServerFactory::class)->make();
});

test('custom token models and disabled rotation are rejected', function (string $property, mixed $value): void {
    Passport::${$property} = $value;
    $this->expectException(LogicException::class);

    $this->app->make(OwnedAuthorizationServerFactory::class)->make();
})->with([
    ['tokenModel', UnexpectedOAuthToken::class],
    ['refreshTokenModel', UnexpectedOAuthRefreshToken::class],
    ['revokeRefreshTokenAfterUse', false],
    ['passwordGrantEnabled', true],
    ['implicitGrantEnabled', true],
    ['clientModel', UnexpectedOAuthClient::class],
    ['authCodeModel', UnexpectedOAuthAuthCode::class],
    ['deviceCodeModel', UnexpectedOAuthDeviceCode::class],
    ['accessTokenEntity', UnexpectedOAuthAccessToken::class],
]);

test('contextual client repository dependencies are checked', function (): void {
    $this->app->when(PassportBridgeClientRepository::class)
        ->needs(ClientRepository::class)
        ->give(UnexpectedOAuthClientRepository::class);
    $this->expectException(LogicException::class);

    $this->app->make(OwnedAuthorizationServerFactory::class)->make();
});

test('a contextual hasher cannot weaken confidential client authentication', function (): void {
    $this->app->when(PassportBridgeClientRepository::class)
        ->needs(Hasher::class)
        ->give(fn () => Mockery::mock(Hasher::class));
    $this->expectException(LogicException::class);

    $this->app->make(OwnedAuthorizationServerFactory::class)->make();
});

test('a contextual scope client repository cannot bypass scope filtering', function (): void {
    $this->app->when(ScopeRepository::class)
        ->needs(ClientRepository::class)
        ->give(UnexpectedOAuthClientRepository::class);
    $this->expectException(LogicException::class);

    $this->app->make(OwnedAuthorizationServerFactory::class)->make();
});

test('a prebound factory does not permit early contextual server resolution', function (string $consumer, string $server): void {
    $original = $this->app;
    $early = require base_path('bootstrap/app.php');
    try {
        $early->instance(OwnedAuthorizationServerFactory::class, new stdClass);
        $early->when($consumer)->needs($server)->give(fn () => Mockery::mock($server));
        $this->expectException(LogicException::class);

        $early->make($consumer);
    } finally {
        Container::setInstance($original);
        Facade::setFacadeApplication($original);
    }
})->with([
    [OAuthServerConsumer::class, AuthorizationServer::class],
    [OAuthResourceServerConsumer::class, ResourceServer::class],
]);

test('OAuth server construction is refused before Passport configuration is ready', function (): void {
    $factory = new OwnedAuthorizationServerFactory($this->app);
    $this->expectException(LogicException::class);

    $factory->make();
});

test('registering the provider refuses a cached authorization server', function (): void {
    $this->app->instance(AuthorizationServer::class, Mockery::mock(AuthorizationServer::class));
    $this->expectException(LogicException::class);

    (new AppServiceProvider($this->app))->register();
});

test('a replacement server remains rejected even after a failed resolution', function (): void {
    $this->app->singleton(AuthorizationServer::class, fn () => Mockery::mock(AuthorizationServer::class));
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $this->app->make(AuthorizationServer::class);
            $this->fail('An unowned server was accepted.');
        } catch (LogicException $exception) {
            expect($exception->getMessage())->toContain('Unowned OAuth service');
        }
    }
});

test('a contextual server factory cannot bypass ownership checks', function (): void {
    $this->app->when(OAuthServerConsumer::class)
        ->needs(AuthorizationServer::class)
        ->give(fn () => Mockery::mock(AuthorizationServer::class));
    $this->expectException(LogicException::class);

    $this->app->make(OAuthServerConsumer::class);
});

test('the Passport token route accepts its normal owned controller', function (): void {
    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code'])
        ->assertBadRequest()
        ->assertJsonPath('error', 'invalid_request');
});

test('the Passport token route rejects a controller factory holding an unowned server', function (): void {
    $server = Mockery::mock(AuthorizationServer::class);
    $server->shouldNotReceive('respondToAccessTokenRequest');
    $this->app->bind(AccessTokenController::class, fn () => new AccessTokenController($server));

    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code'])
        ->assertInternalServerError();
});

test('the Passport token route rejects a contextual unowned server', function (): void {
    $server = Mockery::mock(AuthorizationServer::class);
    $server->shouldNotReceive('respondToAccessTokenRequest');
    $this->app->when(AccessTokenController::class)
        ->needs(AuthorizationServer::class)
        ->give(fn () => $server);

    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code'])
        ->assertInternalServerError();
});

test('the Passport token route rejects a cached controller holding an unowned server', function (): void {
    $server = Mockery::mock(AuthorizationServer::class);
    $server->shouldNotReceive('respondToAccessTokenRequest');
    $this->app->instance(AccessTokenController::class, new AccessTokenController($server));

    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code'])
        ->assertInternalServerError();
});

test('a contextual personal access factory must be owned', function (): void {
    $server = Mockery::mock(AuthorizationServer::class);
    $this->app->when(OAuthPersonalTokenConsumer::class)
        ->needs(PersonalAccessTokenFactory::class)
        ->give(fn () => new PersonalAccessTokenFactory($server));
    $this->expectException(LogicException::class);

    $this->app->make(OAuthPersonalTokenConsumer::class);
});

test('an application-defined OAuth route is outside the Passport token route guard', function (): void {
    $server = Mockery::mock(AuthorizationServer::class);
    $server->shouldReceive('respondToAccessTokenRequest')->once()->andReturn($this->app->make(ResponseInterface::class));
    Route::post('/custom-oauth/token', function () use ($server) {
        $server->respondToAccessTokenRequest(app(ServerRequestInterface::class), app(ResponseInterface::class));

        return response()->noContent();
    })->name('custom.oauth.token');

    $this->postJson('/custom-oauth/token', [])->assertNoContent();
});

test('an owned server refuses additional grants and disabling refresh rotation', function (string $operation): void {
    $server = $this->app->make(AuthorizationServer::class);
    $this->expectException(LogicException::class);

    if ($operation === 'grant') {
        $server->enableGrantType(new ClientCredentialsGrant);
    } else {
        $server->revokeRefreshTokens(false);
    }
})->with(['grant', 'rotation']);

test('a cached server rejects model changes before processing a token request', function (): void {
    $server = $this->app->make(AuthorizationServer::class);
    Passport::useRefreshTokenModel(UnexpectedOAuthRefreshToken::class);
    $request = (new PsrHttpFactory)->createRequest(Request::create('/oauth/token', 'POST', ['grant_type' => 'refresh_token']));
    $this->expectException(LogicException::class);

    $server->respondToAccessTokenRequest($request, $this->app->make(ResponseInterface::class));
});

test('personal access grants cannot be installed as the public authorization server', function (): void {
    $personal = $this->app->make(OwnedAuthorizationServerFactory::class)->makePersonalAccess();
    $this->app->instance(AuthorizationServer::class, $personal);
    $this->expectException(LogicException::class);

    $this->app->make(AuthorizationServer::class);
});

test('the personal access token factory is owned and separately constructed', function (): void {
    $factory = $this->app->make(PersonalAccessTokenFactory::class);
    $server = (new ReflectionProperty(PersonalAccessTokenFactory::class, 'server'))->getValue($factory);

    expect($this->app->make(OwnedAuthorizationServerFactory::class)->owns($factory))->toBeTrue()
        ->and($server)->not->toBe($this->app->make(AuthorizationServer::class));
});

test('configured grants reject missing request parameters', function (string $method, string $grant, string $parameter): void {
    $server = $this->app->make(OwnedAuthorizationServerFactory::class)->{$method}();
    $request = (new PsrHttpFactory)->createRequest(Request::create('/oauth/token', 'POST', ['grant_type' => $grant]));

    try {
        $server->respondToAccessTokenRequest($request, $this->app->make(ResponseInterface::class));
        $this->fail('The incomplete request was accepted.');
    } catch (OAuthServerException $exception) {
        expect($exception->getErrorType())->toBe('invalid_request')
            ->and($exception->getHint())->toBe('Check the `'.$parameter.'` parameter');
    }
})->with([
    ['make', 'authorization_code', 'client_id'],
    ['make', 'refresh_token', 'refresh_token'],
    ['make', 'urn:ietf:params:oauth:grant-type:device_code', 'client_id'],
    ['makePersonalAccess', 'personal_access', 'user_id'],
]);

test('assertCompatibleVersions passes without exception on current environment', function (): void {
    $factory = new OwnedAuthorizationServerFactory($this->app);

    expect(fn () => $factory->assertCompatibleVersions())->not->toThrow(Throwable::class);
});

test('assertCompatibleVersions permits 13.38.0 and 13.39.0 with or without v prefix', function (string $version): void {
    $vendorsRef = new ReflectionProperty(InstalledVersions::class, 'installedByVendor');
    $installedRef = new ReflectionProperty(InstalledVersions::class, 'installed');

    InstalledVersions::getPrettyVersion('laravel/framework');
    $originalVendors = $vendorsRef->getValue();
    $originalInstalled = $installedRef->getValue();

    try {
        $vendors = $originalVendors;
        foreach ($vendors as $dir => &$data) {
            if (isset($data['versions']['laravel/framework'])) {
                $data['versions']['laravel/framework']['pretty_version'] = $version;
            }
        }
        $vendorsRef->setValue(null, $vendors);

        $installed = $originalInstalled;
        if (isset($installed['versions']['laravel/framework'])) {
            $installed['versions']['laravel/framework']['pretty_version'] = $version;
            $installedRef->setValue(null, $installed);
        }

        $factory = new OwnedAuthorizationServerFactory($this->app);
        expect(fn () => $factory->assertCompatibleVersions())->not->toThrow(Throwable::class);
    } finally {
        $vendorsRef->setValue(null, $originalVendors);
        $installedRef->setValue(null, $originalInstalled);
    }
})->with([
    'v13.38.0',
    '13.38.0',
    'v13.39.0',
    '13.39.0',
]);

test('calling assertCompatibleVersions when versions are incompatible throws LogicException', function (string $package, string $version): void {
    $vendorsRef = new ReflectionProperty(InstalledVersions::class, 'installedByVendor');
    $installedRef = new ReflectionProperty(InstalledVersions::class, 'installed');

    InstalledVersions::getPrettyVersion($package);
    $originalVendors = $vendorsRef->getValue();
    $originalInstalled = $installedRef->getValue();

    try {
        $vendors = $originalVendors;
        foreach ($vendors as $dir => &$data) {
            if (isset($data['versions'][$package])) {
                $data['versions'][$package]['pretty_version'] = $version;
            }
        }
        $vendorsRef->setValue(null, $vendors);

        $installed = $originalInstalled;
        if (isset($installed['versions'][$package])) {
            $installed['versions'][$package]['pretty_version'] = $version;
            $installedRef->setValue(null, $installed);
        }

        $factory = new OwnedAuthorizationServerFactory($this->app);
        expect(fn () => $factory->assertCompatibleVersions())
            ->toThrow(LogicException::class, 'The owned OAuth server requires review for this Laravel, Passport or OAuth server version.');
    } finally {
        $vendorsRef->setValue(null, $originalVendors);
        $installedRef->setValue(null, $originalInstalled);
    }
})->with([
    ['laravel/framework', '13.37.0'],
    ['laravel/framework', 'v13.40.0'],
    ['laravel/passport', 'v13.9.0'],
    ['laravel/passport', '13.10.0'],
    ['league/oauth2-server', '9.4.0'],
    ['league/oauth2-server', 'v9.4.1'],
]);
