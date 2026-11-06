<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\ApplicationInvitation;
use App\Models\TenantApplicationEnvironment;
use App\Models\User;
use App\Notifications\ApplicationInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Creates and redeems invitations to join an application.
 *
 * Invitations are what make invite-only access workable: an administrator names
 * the address, and only that address can redeem the link.
 */
class ApplicationInvitationService
{
    /**
     * Invite an address, replacing any invitation that is still outstanding for
     * it so only the newest link works.
     */
    public function invite(
        TenantApplicationEnvironment $environment,
        string $email,
        string $role = 'member',
        ?User $invitedBy = null,
    ): ApplicationInvitation {
        $email = mb_strtolower(trim($email));
        $token = ApplicationInvitation::generateToken();

        return DB::transaction(function () use ($environment, $email, $role, $invitedBy, $token): ApplicationInvitation {
            ApplicationInvitation::query()
                ->where('tenant_application_environment_id', $environment->getKey())
                ->where('email', $email)
                ->pending()
                ->update(['revoked_at' => now()]);

            $invitation = ApplicationInvitation::query()->create([
                'tenant_application_environment_id' => $environment->getKey(),
                'email' => $email,
                'role' => $role,
                'token_hash' => ApplicationInvitation::hashToken($token),
                'invited_by' => $invitedBy?->getKey(),
                'expires_at' => now()->addDays(ApplicationInvitation::VALID_FOR_DAYS),
            ]);

            $this->send($invitation, $token, $environment);

            return $invitation;
        });
    }

    /**
     * Issue a fresh token for an invitation that is still outstanding, for when
     * the original email was lost.
     */
    public function resend(ApplicationInvitation $invitation): ApplicationInvitation
    {
        $token = ApplicationInvitation::generateToken();

        $invitation->forceFill([
            'token_hash' => ApplicationInvitation::hashToken($token),
            'expires_at' => now()->addDays(ApplicationInvitation::VALID_FOR_DAYS),
        ])->save();

        $this->send($invitation, $token, $invitation->environment);

        return $invitation;
    }

    /**
     * Send the invitation email to the invited address. The invitation is not
     * tied to a user account yet, so it is delivered on demand.
     */
    private function send(ApplicationInvitation $invitation, string $token, TenantApplicationEnvironment $environment): void
    {
        Notification::route('mail', $invitation->email)
            ->notify(new ApplicationInvitationNotification($token, $environment));
    }

    /**
     * Find a usable invitation for the presented token.
     */
    public function find(string $token): ?ApplicationInvitation
    {
        if (trim($token) === '') {
            return null;
        }

        /** @var ApplicationInvitation|null $invitation */
        $invitation = ApplicationInvitation::query()
            ->where('token_hash', ApplicationInvitation::hashToken($token))
            ->first();

        return $invitation?->isUsable() === true ? $invitation : null;
    }
}
