<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginVerificationCode extends Notification
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your KernelBridge login verification code')
            ->greeting('Hello '.$notifiable->name)
            ->line('Your login verification code is '.$this->code.'.')
            ->line('This code expires in five minutes and can only be used once.')
            ->line('If you did not attempt to sign in, you can ignore this email.');
    }
}
