<?php

declare(strict_types=1);

namespace App\Policies;

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
        return $authUser->can('View:WebhookEndpoint');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:WebhookEndpoint');
    }

    public function update(AuthUser $authUser, WebhookEndpoint $webhookEndpoint): bool
    {
        return $authUser->can('Update:WebhookEndpoint');
    }

    public function delete(AuthUser $authUser, WebhookEndpoint $webhookEndpoint): bool
    {
        return $authUser->can('Delete:WebhookEndpoint');
    }

    public function restore(AuthUser $authUser, WebhookEndpoint $webhookEndpoint): bool
    {
        return $authUser->can('Restore:WebhookEndpoint');
    }

    public function forceDelete(AuthUser $authUser, WebhookEndpoint $webhookEndpoint): bool
    {
        return $authUser->can('ForceDelete:WebhookEndpoint');
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
        return $authUser->can('Replicate:WebhookEndpoint');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:WebhookEndpoint');
    }
}
