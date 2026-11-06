<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ApplicationInvitation;
use App\Models\TenantApplicationEnvironment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly TenantApplicationEnvironment $environment,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $application = $this->environment->application;
        $organization = $application->tenant->name;

        return (new MailMessage)
            ->subject('You have been invited to '.$application->name)
            ->greeting('You have been invited')
            ->line($organization.' invited you to join '.$application->name.' ('.$this->environment->environment.').')
            ->action('Accept invitation', $this->url())
            ->line('This invitation expires on '.$this->expiresAt().'.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }

    public function url(): string
    {
        return url('/invitations/'.$this->token);
    }

    private function expiresAt(): string
    {
        return now()->addDays(ApplicationInvitation::VALID_FOR_DAYS)->toFormattedDateString();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'environment_id' => $this->environment->getKey(),
        ];
    }
}
