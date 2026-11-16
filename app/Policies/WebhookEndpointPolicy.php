<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class WebhookEndpointPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:WebhookEndpoint');
    }

    public function view(AuthUser $authUser, WebhookEndpoint $webhookEndpoint): bool
    {
        return $authUser->can('View:WebhookEndpoint') && $this->belongsToUserTenant($authUser, $webhookEndpoint);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:WebhookEndpoint');
    }

    public function update(AuthUser $authUser, WebhookEndpoint $webhookEndpoint): bool
    {
        return $authUser->can('Update:WebhookEndpoint') && $this->belongsToUserTenant($authUser, $webhookEndpoint);
    }

    public function delete(AuthUser $authUser, WebhookEndpoint $webhookEndpoint): bool
    {
        return $authUser->can('Delete:WebhookEndpoint') && $this->belongsToUserTenant($authUser, $webhookEndpoint);
    }

    public function restore(AuthUser $authUser, WebhookEndpoint $webhookEndpoint): bool
    {
        return $authUser->can('Restore:WebhookEndpoint') && $this->belongsToUserTenant($authUser, $webhookEndpoint);
    }

    public function forceDelete(AuthUser $authUser, WebhookEndpoint $webhookEndpoint): bool
    {
        return $authUser->can('ForceDelete:WebhookEndpoint') && $this->belongsToUserTenant($authUser, $webhookEndpoint);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:WebhookEndpoint');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:WebhookEndpoint');
    }

    public function replicate(AuthUser $authUser, WebhookEndpoint $webhookEndpoint): bool
    {
        return $authUser->can('Replicate:WebhookEndpoint') && $this->belongsToUserTenant($authUser, $webhookEndpoint);
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:WebhookEndpoint');
    }

    private function belongsToUserTenant(AuthUser $authUser, WebhookEndpoint $webhookEndpoint): bool
    {
        if (! $authUser instanceof User) {
            return false;
        }

        if ($authUser->hasRole('super_admin')) {
            return true;
        }

        $tenantId = $webhookEndpoint->environment?->application?->tenant_id;

        return $tenantId !== null && $authUser->tenants()->whereKey($tenantId)->exists();
    }
}
