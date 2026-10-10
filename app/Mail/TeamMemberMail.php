<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells a team member how to sign in: the address, the password the owner chose, and the link. Sent straight away. */
class TeamMemberMail extends Mailable
{
    public function __construct(
        public User $member,
        public string $password,
        public bool $created,
    ) {}

    public function envelope(): Envelope
    {
        $company = $this->member->company?->name ?? config('app.name');

        return new Envelope(
            subject: $this->created
                ? __('Your access to :company on :app', ['company' => $company, 'app' => config('app.name')])
                : __('Your new password for :company', ['company' => $company]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.team-member',
            with: [
                'member' => $this->member,
                'company' => $this->member->company,
                'password' => $this->password,
                'created' => $this->created,
                'loginUrl' => rtrim((string) config('fatura.frontend_url'), '/').'/login',
                // The shared mail layout prints it in the footer.
                'supportEmail' => config('fatura.support.email'),
            ],
        );
    }
}
