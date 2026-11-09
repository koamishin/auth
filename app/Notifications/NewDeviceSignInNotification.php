<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an account signs in from a device it has not used before.
 *
 * The email carries a one-click button that signs the new session out.
 */
class NewDeviceSignInNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $deviceName,
        public readonly ?string $ipAddress,
        public readonly string $signedInAt,
        public readonly string $revocationUrl,
        public readonly ?string $supportEmail = null,
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
        $mail = (new MailMessage)
            ->subject('New sign-in to '.config('app.name'))
            ->line('Your account was signed in from a device it has not used before.')
            ->line('**'.$this->deviceName.($this->ipAddress ? ' from '.$this->ipAddress : '').'**')
            ->line('at **'.$this->signedInAt.'**');

        if ($this->supportEmail !== null) {
            $mail->line('If this was not you, sign out that device below and contact **'.$this->supportEmail.'**.');
        } else {
            $mail->line('If this was not you, sign out that device below and change your password.');
        }

        return $mail
            ->action('Sign out this device', $this->revocationUrl)
            ->line('The button signs that device out immediately. The link works for 24 hours.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'device' => $this->deviceName,
            'ip_address' => $this->ipAddress,
        ];
    }
}
