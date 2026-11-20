<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use App\Services\Webhooks\WebhookDispatcher;

class UserObserver
{
    public function created(User $user): void
    {
        WebhookDispatcher::dispatchForUser('user.created', $user);
    }

    public function updated(User $user): void
    {
        if (! $user->wasChanged([
            'name',
            'first_name',
            'last_name',
            'email',
            'username',
            'phone_number',
            'email_verified_at',
            'phone_verified_at',
            'banned_at',
            'ban_reason',
        ])) {
            return;
        }

        WebhookDispatcher::dispatchForUser('user.updated', $user);
    }

    public function deleting(User $user): void
    {
        $user->setRelation('pendingWebhookDeletion', WebhookDispatcher::pendingDeletion($user));
    }

    public function deleted(User $user): void
    {
        WebhookDispatcher::dispatchPending($user->getRelation('pendingWebhookDeletion'));
    }
}
