<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\EmailCodePurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly EmailCodePurpose $purpose,
        public readonly int $expiresInMinutes,
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
        return (new MailMessage)
            ->subject($this->code.' is your '.config('app.name').' code')
            ->greeting($this->purpose->label())
            ->line('Enter this code to continue:')
            ->line('**'.$this->code.'**')
            ->line('The code expires in '.$this->expiresInMinutes.' minutes and can only be used once.')
            ->line('If you did not request this code, you can safely ignore this email.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'purpose' => $this->purpose->value,
        ];
    }
}
