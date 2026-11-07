<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

class ApplicationProvisioner
{
    public const SUPPORTED_FRAMEWORKS = ['laravel', 'nextjs', 'vue', 'react', 'nodejs', 'vanilla', 'mobile'];

    public function __construct(private readonly ClientRepository $clients) {}

    public static function defaultClientTypeForFramework(?string $framework): ?string
    {
        if ($framework === null || $framework === '') {
            return null;
        }

        return in_array($framework, ['laravel', 'nodejs'], true) ? 'confidential' : 'public';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Tenant $tenant, array $data): TenantApplication
    {
        $name = $data['name'] ?? null;
        $rawFramework = $data['framework'] ?? null;
        $environment = $data['environment'] ?? 'development';
        $inputRedirectUris = $data['redirect_uris'] ?? null;
        $description = $data['description'] ?? null;
        $rawClientType = $data['client_type'] ?? null;

        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'Provide an application name of at most 255 characters.']);
        }

        $framework = null;
        if ($rawFramework !== null && $rawFramework !== '') {
            if (! is_string($rawFramework) || ! in_array($rawFramework, self::SUPPORTED_FRAMEWORKS, true)) {
                throw ValidationException::withMessages(['framework' => 'Select a valid application framework.']);
            }
            $framework = $rawFramework;
        }

        if (! is_string($environment) || ! in_array($environment, ['development', 'production'], true)) {
            throw ValidationException::withMessages(['environment' => 'Select a valid environment.']);
        }

        if ($inputRedirectUris !== null && ! is_array($inputRedirectUris)) {
            throw ValidationException::withMessages(['redirect_uris' => 'Provide callback URLs as a list.']);
        }

        if ($description !== null && ! is_string($description)) {
            throw ValidationException::withMessages(['description' => 'The description must be text.']);
        }

        $allowSignups = $data['allow_signups'] ?? ($environment === 'development');
        if (! is_bool($allowSignups)) {
            throw ValidationException::withMessages(['allow_signups' => 'Choose whether self-service signup is allowed.']);
        }

        $redirectUris = [];
        foreach ($inputRedirectUris ?? [] as $uri) {
            if (! is_string($uri) || trim($uri) === '') {
                throw ValidationException::withMessages(['redirect_uris' => 'Each callback URL must be text.']);
            }

            $redirectUris[] = trim($uri);
        }

        if ($redirectUris !== []) {
            $this->validateRedirects($environment, $redirectUris);
        } elseif ($framework !== null && array_key_exists('redirect_uris', $data)) {
            throw ValidationException::withMessages(['redirect_uris' => 'Provide at least one callback URL for the selected environment.']);
        }

        $clientType = $rawClientType ?? self::defaultClientTypeForFramework($framework);
        if ($clientType !== null && (! is_string($clientType) || ! in_array($clientType, ['public', 'confidential'], true))) {
            throw ValidationException::withMessages(['client_type' => 'Choose a valid client architecture.']);
        }

        $data = [
            'name' => trim($name),
            'framework' => $framework,
            'environment' => $environment,
            'redirect_uris' => $redirectUris,
            'description' => $description,
            'allow_signups' => $allowSignups,
            'client_type' => $clientType,
        ];

        [$application, $credentials] = DB::transaction(function () use ($tenant, $data): array {
            $clients = [];
            foreach (['development', 'production'] as $environment) {
                $uris = $environment === $data['environment'] ? $data['redirect_uris'] : [];
                $clients[$environment] = $data['client_type'] === null ? null : $this->clients->createAuthorizationCodeGrantClient(
                    name: $data['name'].' ('.ucfirst($environment).')',
                    redirectUris: $uris,
                    confidential: $data['client_type'] === 'confidential',
                );
            }

            $selected = $clients[$data['environment']];
            $application = $tenant->applications()->create([
                'name' => $data['name'],
                'framework' => $data['framework'],
                'environment' => $data['environment'],
                'redirect_uris' => $data['redirect_uris'],
                'description' => $data['description'],
                'oauth_client_id' => $selected?->getKey(),
            ]);

            $credentials = [];
            foreach ($clients as $environment => $client) {
                $publishableKey = TenantApplicationEnvironment::generatePublishableKey($environment);
                $secretMaterial = TenantApplicationEnvironment::generateSecretKeyMaterial($environment);
                $envRedirects = $environment === $data['environment'] ? $data['redirect_uris'] : [];
                $application->environments()->create([
                    'environment' => $environment,
                    'oauth_client_id' => $client?->getKey(),
                    'publishable_key' => $publishableKey,
                    'secret_key_lookup' => $secretMaterial['lookup'],
                    'secret_key_hash' => $secretMaterial['hash'],
                    'secret_key_prefix' => $secretMaterial['prefix'],
                    'client_type' => $data['client_type'],
                    'redirect_uris' => $envRedirects,
                    'settings' => [
                        'allow_signups' => $environment === $data['environment']
                            ? $data['allow_signups']
                            : $environment === 'development',
                        // Production requires a verified email by default. The key has
                        // to match what the environment reads, or it would be ignored.
                        // Development is left unset so it inherits the portal default,
                        // and multi-factor auth stays opt-in rather than being forced
                        // on every end user of a new application.
                        ...($environment === 'production' ? ['email_verification_required' => true] : []),
                    ],
                ]);

                $credentials[$environment] = [
                    'publishable_key' => $publishableKey,
                    'secret_key' => $secretMaterial['plain'],
                    'client_id' => $client?->getKey(),
                    'secret' => $client?->plainSecret,
                ];
            }

            return [$application, $credentials];
        });

        session()->put('new_app_credentials', $credentials);
        session()->put('new_app_credentials_application_id', $application->getKey());
        session()->put('new_app_credentials_expires_at', now()->addMinutes(10)->timestamp);

        return $application;
    }

    /**
     * Configure client type (public PKCE vs confidential server) and callback URLs for an environment.
     *
     * @param  array<string, mixed>  $data
     */
    public function connectEnvironment(TenantApplication $application, string $environment, array $data): TenantApplicationEnvironment
    {
        if (! in_array($environment, ['development', 'production'], true)) {
            throw ValidationException::withMessages(['environment' => 'Select a valid environment.']);
        }

        $rawFramework = $data['framework'] ?? $application->framework;
        $framework = null;
        if ($rawFramework !== null && $rawFramework !== '') {
            if (! is_string($rawFramework) || ! in_array($rawFramework, self::SUPPORTED_FRAMEWORKS, true)) {
                throw ValidationException::withMessages(['framework' => 'Select a valid application framework.']);
            }
            $framework = $rawFramework;
        }

        $clientType = $data['client_type'] ?? null;
        if (! is_string($clientType) || ! in_array($clientType, ['public', 'confidential'], true)) {
            throw ValidationException::withMessages(['client_type' => 'Select whether this app runs in a browser/mobile client (PKCE) or on a confidential server.']);
        }

        $inputRedirects = $data['redirect_uris'] ?? null;
        if (! is_array($inputRedirects)) {
            throw ValidationException::withMessages(['redirect_uris' => 'Provide at least one callback URL for the selected environment.']);
        }

        $redirectUris = [];
        foreach ($inputRedirects as $uri) {
            if (! is_string($uri)) {
                throw ValidationException::withMessages(['redirect_uris' => 'Each callback URL must be text.']);
            }
            $trimmed = trim($uri);
            if ($trimmed !== '') {
                $redirectUris[] = $trimmed;
            }
        }
        $redirectUris = array_values(array_unique($redirectUris));

        $this->validateRedirects($environment, $redirectUris);

        return DB::transaction(function () use ($application, $environment, $framework, $clientType, $redirectUris): TenantApplicationEnvironment {
            $config = $application->getEnvironmentConfig($environment);
            /** @var TenantApplicationEnvironment $config */
            $config = $application->environments()->whereKey($config->getKey())->lockForUpdate()->firstOrFail();
            $client = $config->oauthClient;

            if ($client instanceof Client && TenantApplicationEnvironment::query()->where('oauth_client_id', $client->getKey())->count() !== 1) {
                throw ValidationException::withMessages(['client_type' => 'The OAuth client is shared by multiple environments and cannot be changed.']);
            }

            if ($client instanceof Client && $client->getAttribute('revoked')) {
                throw ValidationException::withMessages(['client_type' => 'The existing OAuth client has been revoked.']);
            }

            if (! $client instanceof Client) {
                $client = $this->clients->createAuthorizationCodeGrantClient(
                    name: $application->name.' ('.ucfirst($environment).')',
                    redirectUris: $redirectUris,
                    confidential: $clientType === 'confidential',
                );
            } else {
                if ($client->confidential() !== ($clientType === 'confidential')) {
                    throw ValidationException::withMessages(['client_type' => 'Changing an existing OAuth client type would break connected applications.']);
                }
                $attributes = ['redirect_uris' => $redirectUris];
                $client->forceFill($attributes)->save();
            }

            $config->update([
                'oauth_client_id' => (string) $client->getKey(),
                'client_type' => $clientType,
                'redirect_uris' => $redirectUris,
            ]);

            $appUpdates = [];
            if ($framework !== null) {
                $appUpdates['framework'] = $framework;
            }
            if ($application->environment === $environment) {
                $appUpdates['oauth_client_id'] = (string) $client->getKey();
                $appUpdates['redirect_uris'] = $redirectUris;
            }
            if ($appUpdates !== []) {
                $application->update($appUpdates);
            }

            return $config->fresh(['oauthClient', 'application']) ?? $config;
        });
    }

    /**
     * Rotate the environment-scoped secret key without changing legacy OAuth credentials.
     *
     * @return array{environment: string, publishable_key: string, secret_key: string, client_id: string, secret: string}
     */
    public function rotateSecretKey(TenantApplication $application, ?string $environment = null): array
    {
        $env = $environment ?? $application->environment;
        if (! in_array($env, ['development', 'production'], true)) {
            throw ValidationException::withMessages(['environment' => 'Select a valid environment.']);
        }

        return DB::transaction(function () use ($application, $env): array {
            $config = $application->getEnvironmentConfig($env);
            $client = $config->oauthClient;

            $secretMaterial = TenantApplicationEnvironment::generateSecretKeyMaterial($env);
            $config->forceFill([
                'secret_key_lookup' => $secretMaterial['lookup'],
                'secret_key_hash' => $secretMaterial['hash'],
                'secret_key_prefix' => $secretMaterial['prefix'],
                'secret_key_rotated_at' => now(),
            ])->save();

            return [
                'environment' => $env,
                'publishable_key' => (string) $config->publishable_key,
                'secret_key' => $secretMaterial['plain'],
                'client_id' => (string) ($client?->getKey() ?? ''),
                'secret' => $secretMaterial['plain'],
            ];
        });
    }

    /**
     * Rotate the legacy confidential OAuth 2.0 client secret without invalidating the sk_... secret key.
     *
     * @return array{environment: string, client_id: string, secret: string}
     */
    public function rotateLegacyClientSecret(TenantApplication $application, ?string $environment = null): array
    {
        $env = $environment ?? $application->environment;
        if (! in_array($env, ['development', 'production'], true)) {
            throw ValidationException::withMessages(['environment' => 'Select a valid environment.']);
        }

        return DB::transaction(function () use ($application, $env): array {
            $config = $application->getEnvironmentConfig($env);
            $client = $config->oauthClient;

            if (! $client instanceof Client || $client->getAttribute('revoked')) {
                throw ValidationException::withMessages(['environment' => 'No active OAuth client found for this environment.']);
            }

            if (TenantApplicationEnvironment::query()->where('oauth_client_id', $client->getKey())->count() !== 1) {
                throw ValidationException::withMessages(['environment' => 'The OAuth client is shared by multiple environments and cannot be changed.']);
            }

            if (! $client->confidential()) {
                throw ValidationException::withMessages(['environment' => 'Public PKCE clients do not use a client secret.']);
            }

            $newSecret = Str::random(40);
            $client->forceFill(['secret' => $newSecret])->save();

            return [
                'environment' => $env,
                'client_id' => (string) $client->getKey(),
                'secret' => $newSecret,
            ];
        });
    }

    /**
     * @param  array<int, mixed>  $redirectUris
     */
    public function validateRedirects(string $environment, array $redirectUris): void
    {
        if (! in_array($environment, ['development', 'production'], true) || $redirectUris === []) {
            throw ValidationException::withMessages(['redirect_uris' => 'Provide at least one callback URL for the selected environment.']);
        }

        foreach ($redirectUris as $uri) {
            $parts = is_string($uri) ? parse_url($uri) : false;
            if (! is_array($parts) || ! filter_var($uri, FILTER_VALIDATE_URL)
                || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || ($environment === 'production' && $parts['scheme'] !== 'https')) {
                throw ValidationException::withMessages(['redirect_uris' => 'Use valid callback URLs without credentials or fragments. Production callbacks must use HTTPS.']);
            }
        }
    }
}
