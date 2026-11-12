<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\TenantApplicationEnvironment;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Hashing\ArgonHasher;
use Illuminate\Hashing\BcryptHasher;
use Laravel\Passport\Bridge\ClientRepository as BaseBridgeClientRepository;
use Laravel\Passport\ClientRepository as ClientModelRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use LogicException;

class PassportBridgeClientRepository extends BaseBridgeClientRepository
{
    public function __construct(
        ClientModelRepository $clients,
        Hasher $hasher,
        private readonly ApplicationAccess $access,
    ) {
        if ($clients::class !== ClientModelRepository::class || $access::class !== ApplicationAccess::class
            || ! in_array($hasher::class, [BcryptHasher::class, ArgonHasher::class, Argon2IdHasher::class], true)) {
            throw new LogicException('Custom OAuth client repository dependencies are unsupported.');
        }
        parent::__construct($clients, $hasher);
    }

    #[\Override]
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        if (str_starts_with($clientIdentifier, 'sk_')) {
            return null;
        }

        if (str_starts_with($clientIdentifier, 'pk_')) {
            $environment = $this->access->environmentForPublishableKey($clientIdentifier);
            $record = $environment?->oauthClient;

            return $record && ! $record->getAttribute('revoked') ? $this->fromClientModel($record) : null;
        }

        return parent::getClientEntity($clientIdentifier);
    }

    #[\Override]
    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        if ($clientSecret === null || $clientSecret === '' || str_starts_with($clientSecret, 'pk_')) {
            return false;
        }

        if (str_starts_with($clientIdentifier, 'sk_')) {
            return false;
        }

        if (str_starts_with($clientIdentifier, 'pk_')) {
            if ($grantType !== null && ! in_array($grantType, ['authorization_code', 'refresh_token'], true)) {
                return false;
            }

            $environment = $this->access->environmentForPublishableKey($clientIdentifier);
            $record = $environment?->oauthClient;

            if (! $environment instanceof TenantApplicationEnvironment || ! $record || $record->getAttribute('revoked')) {
                return false;
            }

            if (str_starts_with($clientSecret, 'sk_')) {
                return $environment->verifySecretKey($clientSecret);
            }

            $secret = $record->getAttribute('secret');

            return is_string($secret) && $secret !== '' && $this->hasher->check($clientSecret, $secret);
        }

        if (str_starts_with($clientSecret, 'sk_')) {
            if ($grantType !== null && ! in_array($grantType, ['authorization_code', 'refresh_token'], true)) {
                return false;
            }

            $environment = $this->access->environmentForClient($clientIdentifier);

            return $environment instanceof TenantApplicationEnvironment && $environment->verifySecretKey($clientSecret);
        }

        return parent::validateClient($clientIdentifier, $clientSecret, $grantType);
    }
}
