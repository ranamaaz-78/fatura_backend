<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $owner,
        public Company $company,
        public Subscription $subscription,
        public string $setPasswordUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your :app account is ready', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.account-ready',
            with: [
                'owner' => $this->owner,
                'company' => $this->company,
                'subscription' => $this->subscription,
                'url' => $this->setPasswordUrl,
                'supportEmail' => config('fatura.support.email'),
            ],
        );
    }
}
