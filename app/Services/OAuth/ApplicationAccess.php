<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\Tenant;
use App\Models\TenantApplication;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ApplicationAccess
{
    public function environmentForClient(string $clientId): ?TenantApplicationEnvironment
    {
        if (str_starts_with($clientId, 'sk_')) {
            return null;
        }

        if (str_starts_with($clientId, 'pk_test_') || str_starts_with($clientId, 'pk_live_')) {
            return $this->environmentForPublishableKey($clientId);
        }

        $query = TenantApplicationEnvironment::query();
        $query->with(['application.tenant', 'oauthClient']);
        $query->where('oauth_client_id', $clientId);
        $query->limit(2);
        $environments = $query->get();

        if ($environments->count() !== 1) {
            return null;
        }

        return $this->validatedEnvironment($environments->first());
    }

    public function environmentForPublishableKey(string $publishableKey): ?TenantApplicationEnvironment
    {
        $expectedEnvironment = match (true) {
            str_starts_with($publishableKey, 'pk_test_') => 'development',
            str_starts_with($publishableKey, 'pk_live_') => 'production',
            default => null,
        };

        if ($expectedEnvironment === null || preg_match('/\Apk_(test|live)_[A-Za-z0-9_-]{16,}\z/', $publishableKey) !== 1) {
            return null;
        }

        $environments = TenantApplicationEnvironment::query()
            ->with(['application.tenant', 'oauthClient'])
            ->where('publishable_key', $publishableKey)
            ->limit(2)
            ->get();

        if ($environments->count() !== 1) {
            return null;
        }

        $environment = $this->validatedEnvironment($environments->first());
        if (! $environment instanceof TenantApplicationEnvironment || $environment->environment !== $expectedEnvironment) {
            return null;
        }

        if (TenantApplicationEnvironment::query()->where('oauth_client_id', $environment->oauth_client_id)->count() !== 1) {
            return null;
        }

        return $environment;
    }

    public function environmentForSecretKey(string $secretKey, bool $requireActiveTenant = true): ?TenantApplicationEnvironment
    {
        $expectedEnvironment = match (true) {
            str_starts_with($secretKey, 'sk_test_') => 'development',
            str_starts_with($secretKey, 'sk_live_') => 'production',
            default => null,
        };

        if ($expectedEnvironment === null || preg_match('/\Ask_(test|live)_[A-Za-z0-9_-]{20,}\z/', $secretKey) !== 1) {
            return null;
        }

        $lookup = hash('sha256', $secretKey);
        /** @var Collection<int, TenantApplicationEnvironment> $environments */
        $environments = TenantApplicationEnvironment::query()
            ->with(['application.tenant', 'oauthClient'])
            ->where('secret_key_lookup', $lookup)
            ->limit(2)
            ->get();

        if ($environments->count() !== 1) {
            return null;
        }

        $environment = $environments->first();
        if (! $environment instanceof TenantApplicationEnvironment
            || $environment->environment !== $expectedEnvironment
            || ! $environment->verifySecretKey($secretKey)) {
            return null;
        }

        $application = $environment->getRelationValue('application');
        $tenant = $application instanceof TenantApplication ? $application->getRelationValue('tenant') : null;

        if (! $tenant instanceof Tenant || ($requireActiveTenant && ! $tenant->is_active)) {
            return null;
        }

        return $environment;
    }

    private function validatedEnvironment(mixed $environment): ?TenantApplicationEnvironment
    {
        if (! $environment instanceof TenantApplicationEnvironment) {
            return null;
        }

        $application = $environment->getRelationValue('application');
        $tenant = $application instanceof TenantApplication ? $application->getRelationValue('tenant') : null;
        $client = $environment->oauthClient;

        if (! $tenant instanceof Tenant || ! $tenant->is_active
            || ! in_array($environment->environment, ['development', 'production'], true)
            || ! $client || $client->getAttribute('revoked')) {
            return null;
        }

        return $environment;
    }

    public function allows(TenantApplicationEnvironment $environment, User $user): bool
    {
        $application = $environment->getRelationValue('application');
        $tenant = $application instanceof TenantApplication ? $application->getRelationValue('tenant') : null;

        return $application instanceof TenantApplication
            && $tenant instanceof Tenant
            && ! $user->isBanned()
            && $tenant->users()->whereKey($user->getKey())->exists()
            && $application->users($environment->environment)->whereKey($user->getKey())->exists();
    }
}
