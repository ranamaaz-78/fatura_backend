<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Mail\BillingInvoiceMail;
use App\Models\BillingInvoice;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\Locales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The invoice YK Digital Solutions sends a company when it buys or renews a subscription: a row that keeps
 * what was billed, an A4 PDF made from it, and the email that carries the PDF to the company's owner.
 */
class BillingInvoiceService
{
    public function __construct(
        private readonly BillingInvoicePdf $pdf,
        private readonly NotificationLogger $logger,
    ) {}

    /**
     * Issue the invoice for a term and email it. Never throws: the account or renewal it belongs to has
     * already happened, so a failure here is logged and recorded on the invoice, not raised.
     */
    public function issueAndSend(Subscription $subscription, ?Payment $payment, int $periods, string $kind): ?BillingInvoice
    {
        try {
            $invoice = $this->issue($subscription, $payment, $periods, $kind);
        } catch (Throwable $e) {
            Log::error('Billing invoice could not be issued: '.$e->getMessage());

            return null;
        }

        $this->send($invoice);

        return $invoice->fresh();
    }

    public function issue(Subscription $subscription, ?Payment $payment, int $periods, string $kind = BillingInvoice::KIND_NEW): BillingInvoice
    {
        $company = Company::withoutGlobalScopes()->with('owner')->findOrFail($subscription->company_id);
        $locale = Locales::normalize($company->locale) ?? Locales::DEFAULT;
        $periods = max(1, $periods);

        $total = $payment !== null
            ? (float) $payment->amount
            : round((float) $subscription->plan_price * $periods, 2);
        $unit = round($total / $periods, 2);

        $planName = Locales::using($locale, fn () => $subscription->translated('name') ?? $subscription->plan_name);

        $data = [
            'billed_to' => [
                'name' => $company->name,
                'tax_id' => $company->tax_id,
                'address' => collect([
                    $company->address,
                    trim(($company->postal_code ?? '').' '.($company->city ?? '')),
                    $company->country,
                ])->filter(fn ($part) => filled($part))->implode(', '),
                'email' => $company->owner?->email ?: $company->email,
            ],
            'plan' => ['name' => $planName, 'interval' => $subscription->plan_interval->value],
            'coverage' => [
                'from' => $subscription->starts_at->toIso8601String(),
                'to' => $subscription->ends_at->toIso8601String(),
            ],
            'payment' => $payment === null ? null : [
                'method' => $payment->paymentMethod?->name,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
        ];

        return DB::transaction(fn () => BillingInvoice::create([
            'number' => $this->nextNumber(),
            'company_id' => $company->id,
            'subscription_id' => $subscription->id,
            'payment_id' => $payment?->id,
            'kind' => $kind,
            'locale' => $locale,
            'issued_at' => now(),
            'currency' => $subscription->plan_currency,
            'periods' => $periods,
            'unit_price' => $unit,
            'total' => $total,
            'status' => $payment !== null ? BillingInvoice::STATUS_PAID : BillingInvoice::STATUS_PENDING,
            'data' => $data,
        ]));
    }

    /** Email the PDF to the company's owner. The outcome is kept on the invoice and in the notification log. */
    public function send(BillingInvoice $invoice): void
    {
        $company = Company::withoutGlobalScopes()->with('owner')->find($invoice->company_id);
        $to = $invoice->data['billed_to']['email'] ?? null;
        $error = null;

        if (blank($to)) {
            $error = 'No email address to send the invoice to.';
        } else {
            try {
                Mail::to($to)->locale($invoice->locale)->sendNow(new BillingInvoiceMail($invoice, $this->pdf->render($invoice)));
            } catch (Throwable $e) {
                $error = $e->getMessage();
                Log::error('Billing invoice email failed: '.$error);
            }
        }

        $invoice->forceFill([
            'sent_at' => $error === null ? now() : null,
            'send_error' => $error,
        ])->save();

        $this->logger->log(
            NotificationChannel::Email,
            'billing_invoice',
            (string) $to,
            $error === null ? NotificationStatus::Sent : NotificationStatus::Failed,
            [
                'company_id' => $company?->id,
                'user_id' => $company?->owner?->id,
                'payload' => ['number' => $invoice->number, 'total' => (string) $invoice->total, 'kind' => $invoice->kind],
                'error' => $error,
            ],
        );
    }

    /** YK-2026-0001, YK-2026-0002 ... counting again from 1 each year. */
    private function nextNumber(): string
    {
        $prefix = config('fatura.billing.prefix', 'YK').'-'.now()->year.'-';

        $last = BillingInvoice::query()
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $next = $last === null ? 1 : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
