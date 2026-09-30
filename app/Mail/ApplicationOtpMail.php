<?php

namespace App\Mail;

use App\Services\ApplicationOtp;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent straight away (not queued): the applicant is waiting on it. */
class ApplicationOtpMail extends Mailable
{
    public function __construct(
        public string $name,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':code is your :app verification code', ['code' => $this->code, 'app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.application-otp',
            with: [
                'name' => $this->name,
                'code' => $this->code,
                'minutes' => intdiv(ApplicationOtp::TTL_SECONDS, 60),
                'supportEmail' => config('fatura.support.email'),
            ],
        );
    }
}
