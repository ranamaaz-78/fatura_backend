<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent straight away (not queued): the person is waiting on the screen for it. */
class PasswordResetMail extends Mailable
{
    public function __construct(
        public User $user,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Reset your :app password', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.password-reset',
            with: [
                'user' => $this->user,
                'url' => $this->resetUrl,
                'minutes' => (int) config('auth.passwords.users.expire'),
                'supportEmail' => config('fatura.support.email'),
            ],
        );
    }
}
