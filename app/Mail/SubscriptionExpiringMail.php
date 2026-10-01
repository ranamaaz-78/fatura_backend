<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionExpiringMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public User $owner,
        public Company $company,
        public Subscription $subscription,
        public int $daysLeft,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your :app subscription ends in :days day(s)', [
                'app' => config('app.name'),
                'days' => $this->daysLeft,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.subscription-expiring',
            with: [
                'owner' => $this->owner,
                'company' => $this->company,
                'subscription' => $this->subscription,
                'daysLeft' => $this->daysLeft,
                'supportEmail' => config('fatura.support.email'),
                'supportWhatsapp' => config('fatura.support.whatsapp'),
            ],
        );
    }
}
