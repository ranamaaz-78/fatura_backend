<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells a team member how to get in: a link to choose their own password. No password is ever put in the email,
 * and the owner never knows it. Sent straight away.
 */
class TeamMemberMail extends Mailable
{
    public function __construct(
        public User $member,
        public string $setPasswordUrl,
        public bool $created,
    ) {}

    public function envelope(): Envelope
    {
        $company = $this->member->company?->name ?? config('app.name');

        return new Envelope(
            subject: $this->created
                ? __('Your access to :company on :app', ['company' => $company, 'app' => config('app.name')])
                : __('Choose a new password for :company', ['company' => $company]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.team-member',
            with: [
                'member' => $this->member,
                'company' => $this->member->company,
                'setPasswordUrl' => $this->setPasswordUrl,
                'created' => $this->created,
                'validHours' => (int) ceil(((int) config('auth.passwords.invites.expire')) / 60),
                // The shared mail layout prints it in the footer.
                'supportEmail' => config('fatura.support.email'),
            ],
        );
    }
}
