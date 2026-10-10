<?php

namespace App\Mail;

use App\Models\BillingInvoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The cover email for a subscription invoice, with the A4 PDF attached. Sent straight away, never queued. */
class BillingInvoiceMail extends Mailable
{
    public function __construct(
        public BillingInvoice $invoice,
        public string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your invoice :number · :app', ['number' => $this->invoice->number, 'app' => config('fatura.billing.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.billing-invoice',
            with: [
                'invoice' => $this->invoice,
                'issuer' => config('fatura.billing'),
                'support' => config('fatura.support'),
                'loginUrl' => rtrim((string) config('fatura.frontend_url'), '/').'/login',
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, $this->invoice->number.'.pdf')->withMime('application/pdf'),
        ];
    }
}
