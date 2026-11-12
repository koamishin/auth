<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use Composer\InstalledVersions;
use DateInterval;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\ClientRepository;
use Laravel\Passport\Bridge\DeviceCodeRepository;
use Laravel\Passport\Bridge\PersonalAccessBearerTokenResponse;
use Laravel\Passport\Bridge\PersonalAccessGrant;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Bridge\ScopeRepository;
use Laravel\Passport\ClientRepository as ClientModelRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenFactory;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\DeviceCodeGrant;
use League\OAuth2\Server\Grant\GrantTypeInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use LogicException;
use ReflectionProperty;
use WeakMap;

final class OwnedAuthorizationServerFactory
{
    /** @var WeakMap<object, true> */
    private WeakMap $servers;

    private bool $ready = false;

    public function __construct(private readonly Application $app)
    {
        $this->servers = new WeakMap;
    }

    public function owns(object $server): bool
    {
        return isset($this->servers[$server]);
    }

    public function markReady(): void
    {
        $this->ready = true;
    }

    public function make(): OwnedAuthorizationServer
    {
        $this->validateConfiguration();
        $authCode = new AuthCodeGrant(
            $this->repository(AuthCodeRepository::class),
            $this->repository(RefreshTokenRepository::class),
            new DateInterval('PT10M'),
        );
        $authCode->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
        $refresh = new FamilyRefreshTokenGrant($this->repository(RefreshTokenRepository::class), $this->families());
        $refresh->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
        $grants = [
            [$authCode, Passport::tokensExpireIn()],
            [$refresh, Passport::tokensExpireIn()],
            [new ClientCredentialsGrant, Passport::clientCredentialsTokensExpireIn()],
        ];

        if (Passport::$deviceCodeGrantEnabled && Route::has('passport.device')) {
            $device = new DeviceCodeGrant(
                $this->repository(DeviceCodeRepository::class),
                $this->repository(RefreshTokenRepository::class),
                new DateInterval('PT10M'),
                route('passport.device'),
                5,
            );
            $device->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
            $device->setIncludeVerificationUriComplete(true);
            $device->setIntervalVisibility(true);
            $grants[] = [$device, Passport::tokensExpireIn()];
        }

        $access = $this->app->make(ApplicationAccess::class);
        if ($access::class !== ApplicationAccess::class) {
            throw new LogicException('Custom application access services are unsupported by grant families.');
        }

        $server = $this->server(new FamilyBearerTokenResponse($this->families(), $access), $grants);
        $this->servers[$server] = true;

        return $server;
    }

    public function makePersonalAccess(): OwnedAuthorizationServer
    {
        $this->validateConfiguration();

        return $this->server(new PersonalAccessBearerTokenResponse, [
            [new PersonalAccessGrant, Passport::personalAccessTokensExpireIn()],
        ]);
    }

    public function makePersonalAccessTokenFactory(): PersonalAccessTokenFactory
    {
        $factory = new PersonalAccessTokenFactory($this->makePersonalAccess());
        $this->servers[$factory] = true;

        return $factory;
    }

    public function makeResourceServer(): OwnedResourceServer
    {
        $this->validateConfiguration();
        $server = new OwnedResourceServer($this->repository(AccessTokenRepository::class), $this->key('public'), $this->families());
        $this->servers[$server] = true;

        return $server;
    }

    /** @param array<array{GrantTypeInterface, DateInterval}> $grants */
    private function server(ResponseTypeInterface $response, array $grants): OwnedAuthorizationServer
    {
        $server = new OwnedAuthorizationServer(
            $this->repository(ClientRepository::class, PassportBridgeClientRepository::class),
            $this->repository(AccessTokenRepository::class),
            $this->scopeRepository(),
            $this->key('private'),
            Passport::tokenEncryptionKey($this->app->make('encrypter')),
            $response,
            $this->families(),
            Passport::$defaultScope,
            $grants,
        );

        return $server;
    }

    public function assertCompatibleVersions(): void
    {
        $laravelVersion = ltrim((string) InstalledVersions::getPrettyVersion('laravel/framework'), 'v');

        if (! in_array($laravelVersion, ['13.38.0', '13.39.0'], true)
            || InstalledVersions::getPrettyVersion('laravel/passport') !== 'v13.10.0'
            || InstalledVersions::getPrettyVersion('league/oauth2-server') !== '9.4.1') {
            throw new LogicException('The owned OAuth server requires review for this Laravel, Passport or OAuth server version.');
        }
    }

    private function validateConfiguration(): void
    {
        if (! $this->ready) {
            throw new LogicException('OAuth services cannot be constructed before Passport is configured.');
        }
        $this->assertCompatibleVersions();
        if (Passport::$passwordGrantEnabled || Passport::$implicitGrantEnabled) {
            throw new LogicException('Password and implicit grants are unsupported by grant families.');
        }
        $this->families()->connection();
    }

    private function families(): GrantFamilies
    {
        $families = $this->app->make(GrantFamilies::class);
        if ($families::class !== GrantFamilies::class) {
            throw new LogicException('Custom grant family services are unsupported.');
        }

        return $families;
    }

    private function scopeRepository(): ScopeRepository
    {
        $scopes = $this->repository(ScopeRepository::class);
        // Passport 13.10 exposes no accessor for the client repository used to filter scopes.
        $clients = (new ReflectionProperty(ScopeRepository::class, 'clients'))->getValue($scopes);
        if (! is_object($clients) || $clients::class !== ClientModelRepository::class) {
            throw new LogicException('Custom OAuth scope repository dependencies are unsupported.');
        }

        return $scopes;
    }

    /** @template T of object
     * @param  class-string<T>  $abstract
     * @param  class-string|null  $expected
     * @return T
     */
    private function repository(string $abstract, ?string $expected = null): object
    {
        $repository = $this->app->make($abstract);
        if ($repository::class !== ($expected ?? $abstract)) {
            throw new LogicException("Custom OAuth repository {$abstract} is unsupported.");
        }

        return $repository;
    }

    private function key(string $type): CryptKey
    {
        $key = str_replace('\\n', "\n", config("passport.{$type}_key") ?? '');
        if ($key === '') {
            $key = 'file://'.Passport::keyPath("oauth-{$type}.key");
        }

        return new CryptKey($key, null, Passport::$validateKeyPermissions);
    }
}
