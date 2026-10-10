<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Mail\BillingInvoiceMail;
use App\Models\BillingInvoice;
use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BillingInvoicePdf;
use App\Services\BillingInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BillingInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $locale = 'es'): Company
    {
        $company = Company::factory()->create(['locale' => $locale]);
        User::factory()->businessAdmin()->create(['company_id' => $company->id, 'email' => 'marta@devpremises.test']);

        return $company->fresh();
    }

    private function term(Company $company, ?float $paid = 86.97): array
    {
        $plan = Plan::factory()->create(['name' => 'Pro', 'price' => 28.99, 'currency' => 'EUR', 'interval' => 'month']);
        $subscription = Subscription::factory()->create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'plan_name' => 'Pro',
            'plan_price' => 28.99,
            'plan_currency' => 'EUR',
            'plan_interval' => 'month',
            'starts_at' => now(),
            'ends_at' => now()->addMonths(3),
        ]);

        $payment = $paid === null ? null : Payment::create([
            'company_id' => $company->id,
            'subscription_id' => $subscription->id,
            'payment_method_id' => PaymentMethod::factory()->create(['name' => 'Bank transfer'])->id,
            'amount' => $paid,
            'currency' => 'EUR',
            'reference' => 'TRF-88213',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return [$subscription, $payment];
    }

    public function test_a_paid_term_is_invoiced_and_emailed_with_the_pdf_attached(): void
    {
        Mail::fake();
        $company = $this->company();
        [$subscription, $payment] = $this->term($company);

        $invoice = app(BillingInvoiceService::class)->issueAndSend($subscription, $payment, 3, BillingInvoice::KIND_NEW);

        $this->assertSame('YK-'.now()->year.'-0001', $invoice->number);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('86.97', $invoice->total);
        $this->assertSame('28.99', $invoice->unit_price);
        $this->assertSame('es', $invoice->locale);
        $this->assertNotNull($invoice->sent_at);

        Mail::assertSent(BillingInvoiceMail::class, fn (BillingInvoiceMail $mail) => $mail->hasTo('marta@devpremises.test')
            && $mail->invoice->is($invoice)
            && str_starts_with($mail->pdf, '%PDF-'));
    }

    public function test_numbers_count_up_within_the_year(): void
    {
        Mail::fake();
        $company = $this->company();
        [$subscription, $payment] = $this->term($company);
        $service = app(BillingInvoiceService::class);

        $first = $service->issueAndSend($subscription, $payment, 3, BillingInvoice::KIND_NEW);
        $second = $service->issueAndSend($subscription, $payment, 3, BillingInvoice::KIND_RENEWAL);

        $this->assertSame('YK-'.now()->year.'-0001', $first->number);
        $this->assertSame('YK-'.now()->year.'-0002', $second->number);
        $this->assertSame('renewal', $second->kind);
    }

    public function test_a_term_with_no_payment_is_invoiced_as_pending(): void
    {
        Mail::fake();
        $company = $this->company();
        [$subscription] = $this->term($company, null);

        $invoice = app(BillingInvoiceService::class)->issueAndSend($subscription, null, 2, BillingInvoice::KIND_NEW);

        $this->assertSame('pending', $invoice->status);
        $this->assertSame('57.98', $invoice->total);
    }

    public function test_the_pdf_is_a_real_a4_document_in_the_language_of_the_company(): void
    {
        $company = $this->company('es');
        [$subscription, $payment] = $this->term($company);
        $invoice = app(BillingInvoiceService::class)->issue($subscription, $payment, 3, BillingInvoice::KIND_NEW);

        $bytes = app(BillingInvoicePdf::class)->render($invoice);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(5000, strlen($bytes));
        // A4 is 595 x 842 points.
        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+595\.\d+\s+841\.\d+\s*\]/', $bytes);
    }

    public function test_a_failing_email_does_not_undo_the_invoice(): void
    {
        $company = $this->company();
        [$subscription, $payment] = $this->term($company);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $invoice = app(BillingInvoiceService::class)->issueAndSend($subscription, $payment, 3, BillingInvoice::KIND_NEW);

        $this->assertNotNull($invoice);
        $this->assertNull($invoice->sent_at);
        $this->assertSame('SMTP down', $invoice->send_error);
    }

    public function test_renewing_a_company_from_the_admin_panel_bills_it_and_names_the_spanish_plan(): void
    {
        Mail::fake();
        $company = $this->company('es');
        $plan = Plan::factory()->create(['name' => 'Starter', 'name_es' => 'Inicial', 'price' => 20, 'currency' => 'EUR', 'interval' => 'month']);

        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum')
            ->postJson("/api/admin/companies/{$company->id}/subscriptions", ['plan_id' => $plan->id, 'periods' => 2])
            ->assertCreated();

        $invoice = BillingInvoice::firstOrFail();

        $this->assertSame('renewal', $invoice->kind);
        $this->assertSame('pending', $invoice->status);
        $this->assertSame('40.00', $invoice->total);
        $this->assertSame('Inicial', $invoice->data['plan']['name']);
        Mail::assertSent(BillingInvoiceMail::class, fn (BillingInvoiceMail $mail) => $mail->hasTo('marta@devpremises.test'));
    }
}
