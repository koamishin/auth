<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Events\RouteMatched;
use Laravel\Passport\Bridge\ClientRepository;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\PersonalAccessTokenFactory;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use LogicException;
use ReflectionProperty;

final class OAuthBootstrapGuard
{
    private bool $registered = false;

    public function register(Application $app): void
    {
        if ($app->resolved(AuthorizationServer::class)
            || $app->resolved(ResourceServer::class)
            || $app->resolved(PersonalAccessTokenFactory::class)) {
            throw new LogicException('Passport servers must not be resolved before grant family protection is registered.');
        }

        $app->bind(ClientRepository::class, PassportBridgeClientRepository::class);
        $factory = new OwnedAuthorizationServerFactory($app);
        $factory->assertCompatibleVersions();
        $app->instance(OwnedAuthorizationServerFactory::class, $factory);
        $app->singleton(AuthorizationServer::class, fn (): \App\Services\OAuth\OwnedAuthorizationServer => $factory->make());
        $app->singleton(ResourceServer::class, fn (): \App\Services\OAuth\OwnedResourceServer => $factory->makeResourceServer());
        $app->bind(PersonalAccessTokenFactory::class, fn (): \Laravel\Passport\PersonalAccessTokenFactory => $factory->makePersonalAccessTokenFactory());
        foreach ([AuthorizationServer::class, ResourceServer::class, PersonalAccessTokenFactory::class] as $abstract) {
            $checking = false;
            $verify = function (object $instance) use ($factory, $abstract): void {
                if (! $instance instanceof $abstract || ! $factory->owns($instance)) {
                    throw new LogicException("Unowned OAuth service {$abstract} is unsupported.");
                }
            };
            // Laravel caches a singleton before after-resolving callbacks run. Recheck
            // cached instances so a failed ownership check cannot be bypassed on retry.
            $app->beforeResolving($abstract, function () use ($app, $abstract, $verify, &$checking): void {
                if (! $app->resolved($abstract)) {
                    return;
                }

                $checking = true;
                try {
                    $verify($app->make($abstract));
                } finally {
                    $checking = false;
                }
            });
            $app->afterResolving($abstract, $verify);
            unset($checking);
        }
        $app['events']->listen(RouteMatched::class, function (RouteMatched $event) use ($factory): void {
            if ($event->route->getName() !== 'passport.token') {
                return;
            }

            $factory->assertCompatibleVersions();
            if ($event->route->getControllerClass() !== AccessTokenController::class) {
                throw new LogicException('The Passport token route must use the supported token controller.');
            }

            $controller = $event->route->getController();
            if ($controller::class !== AccessTokenController::class) {
                throw new LogicException('Custom Passport token controllers are unsupported.');
            }

            $server = (new ReflectionProperty(AccessTokenController::class, 'server'))->getValue($controller);
            if (! $server instanceof OwnedAuthorizationServer || ! $factory->owns($server)) {
                throw new LogicException('The Passport token controller must use the owned authorization server.');
            }
        });
        $this->registered = true;
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }
}
