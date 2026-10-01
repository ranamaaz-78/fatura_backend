<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Only used when something calls the invites broker directly; the convert flow
 * sends AccountReadyMail instead so the invite link ships with onboarding copy.
 */
class InvitePasswordNotification extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('fatura.frontend_url'), '/')
            .'/set-password?token='.$this->token
            .'&email='.urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject(__('Set your :app password', ['app' => config('app.name')]))
            ->line(__('Use the button below to choose a password for your account.'))
            ->action(__('Set password'), $url)
            ->line(__('This link expires in 48 hours.'));
    }
}
