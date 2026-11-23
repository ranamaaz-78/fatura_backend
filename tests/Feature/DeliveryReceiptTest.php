<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The delivery receipt (nota de entrega): its own kind of document. It carries prices, IVA and a total and takes the
 * goods out of stock, but names no client and takes no payment; it is never edited, paid, voided or converted. It is
 * deleted (stock back) while nothing stands on it, and it never counts as a sale on the dashboard, reports or payments.
 */
class DeliveryReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 20]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'type' => 'nota_entrega',
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'lines' => [[
                'product_id' => $this->product->id,
                'article' => $this->product->article,
                'quantity' => 3,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ], $extra);
    }

    private function issue(array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->postJson('/api/app/sales', $this->payload($extra));
    }

    public function test_a_receipt_is_issued_without_a_client_with_prices_only_and_takes_the_stock(): void
    {
        // The request asks for 21 % IVA (see payload()); a delivery receipt carries none.
        $response = $this->issue()->assertCreated()
            ->assertJsonPath('data.type', 'nota_entrega')
            ->assertJsonPath('data.base_cents', 3000)
            ->assertJsonPath('data.tax_cents', 0)
            ->assertJsonPath('data.total_cents', 3000)
            ->assertJsonPath('data.lines.0.iva_percent', fn ($iva) => (float) $iva === 0.0)
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.customer_id', null);

        $this->assertStringStartsWith('NE-', $response->json('data.number'));
        $this->assertSame('', (string) $response->json('data.client_name'));
        $this->assertSame(17, $this->product->fresh()->quantity);
    }

    public function test_no_iva_recargo_or_discount_ever_reaches_a_receipt(): void
    {
        $recargo = \App\Models\RecargoRate::withoutGlobalScopes()->where('company_id', $this->company->id)->value('id');

        // A bill discount or recargo is refused outright, as on a proforma / delivery note.
        $this->issue(['discount_type' => 'percent', 'discount_value' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('discount_type');
        $this->issue(['recargo_rate_id' => $recargo])
            ->assertStatus(422)->assertJsonValidationErrors('recargo_rate_id');
        $this->assertSame(20, $this->product->fresh()->quantity);

        // A line discount and IVA sent anyway are stripped: price and total only.
        $document = $this->issue([
            'lines' => [[
                'product_id' => $this->product->id, 'article' => 'Lamp', 'quantity' => 2,
                'unit_price' => 1500, 'discount_percent' => 50, 'iva_percent' => 21,
            ]],
        ])->assertCreated();

        $document->assertJsonPath('data.base_cents', 3000)
            ->assertJsonPath('data.tax_cents', 0)
            ->assertJsonPath('data.discount_cents', 0)
            ->assertJsonPath('data.recargo_cents', 0)
            ->assertJsonPath('data.total_cents', 3000)
            ->assertJsonPath('data.lines.0.discount_percent', fn ($d) => (float) $d === 0.0)
            ->assertJsonPath('data.lines.0.iva_percent', fn ($iva) => (float) $iva === 0.0);
    }

    public function test_client_details_and_a_paid_status_sent_anyway_are_ignored(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        $response = $this->issue([
            'customer_id' => $customer->id,
            'save_customer' => true,
            'client_name' => 'Someone',
            'client_phone' => '+34600111222',
            'client_nif' => 'X1234567L',
            'payment_status' => 'paid',
            'payment_method_id' => 1,
        ])->assertCreated();

        $document = SalesDocument::query()->findOrFail($response->json('data.id'));
        $this->assertNull($document->customer_id);
        $this->assertSame('', $document->client_name);
        $this->assertNull($document->client_phone);
        $this->assertNull($document->client_nif);
        $this->assertSame('pending', $document->payment_status);
        $this->assertNull($document->payment_method_id);
        // No client was saved from it.
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_it_is_never_edited_paid_voided_or_converted(): void
    {
        $id = $this->issue()->assertCreated()->json('data.id');
        $as = fn () => $this->actingAs($this->owner, 'sanctum');

        $as()->patchJson("/api/app/sales/{$id}", $this->payload(['client_name' => 'X']))->assertStatus(422);
        $as()->patchJson("/api/app/sales/{$id}/payment", ['payment_status' => 'paid'])->assertStatus(422);
        $as()->postJson("/api/app/sales/{$id}/void", ['reason' => 'Mistake'])->assertStatus(422);
        $as()->postJson("/api/app/sales/{$id}/convert", ['type' => 'factura'])->assertStatus(422);
        $as()->postJson("/api/app/sales/{$id}/invoice")->assertStatus(422);

        $as()->getJson("/api/app/sales/{$id}")->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_invoice', false)
            ->assertJsonPath('data.can_delete', true);
        $this->assertSame(17, $this->product->fresh()->quantity);
    }

    public function test_deleting_puts_the_stock_back_and_restoring_takes_it_again(): void
    {
        $id = $this->issue()->assertCreated()->json('data.id');

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/app/sales/{$id}")->assertOk();
        $this->assertSame(20, $this->product->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$id}/restore")->assertOk();
        $this->assertSame(17, $this->product->fresh()->quantity);
    }

    public function test_it_cannot_take_more_than_is_in_stock(): void
    {
        $this->issue(['lines' => [[
            'product_id' => $this->product->id, 'article' => 'X', 'quantity' => 21,
            'unit_price' => 1000, 'discount_percent' => 0, 'iva_percent' => 21,
        ]]])->assertStatus(422);

        $this->assertSame(20, $this->product->fresh()->quantity);
    }

    public function test_it_has_its_own_list_and_never_counts_as_a_sale(): void
    {
        $this->issue()->assertCreated();
        $as = fn () => $this->actingAs($this->owner, 'sanctum');

        $as()->getJson('/api/app/sales?type=nota_entrega')->assertOk()->assertJsonCount(1, 'data.items');
        $as()->getJson('/api/app/sales?type=factura')->assertOk()->assertJsonCount(0, 'data.items');

        // Not money: the dashboard, the payments and the sales report do not see it.
        $as()->getJson('/api/app/dashboard')->assertOk()->assertJsonPath('data.kpis.document_count', 0);
        $as()->getJson('/api/app/payments')->assertOk()->assertJsonCount(0, 'data.items');
        $as()->getJson('/api/app/reports/sales')->assertOk()->assertJsonPath('data.kpis.document_count', 0);

        // The same report does count an invoice, so the zero above is real.
        $this->issue(['type' => 'factura', 'client_name' => 'Ana'])->assertCreated();
        $as()->getJson('/api/app/reports/sales')->assertOk()->assertJsonPath('data.kpis.document_count', 1);
        $as()->getJson('/api/app/dashboard')->assertOk()->assertJsonPath('data.kpis.document_count', 1);

        // It shows in the trash counts once deleted, like the other kinds.
        $as()->getJson('/api/app/sales/trash-counts')->assertOk()->assertJsonPath('data.nota_entrega', 0);
    }

    public function test_it_has_its_own_numbering_and_printable(): void
    {
        $first = $this->issue()->assertCreated()->json('data.number');
        $second = $this->issue()->assertCreated()->json('data.number');
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('#^NE-\d{6}/0000[12]$#', $first);

        $templates = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/app/print-templates')->assertOk()->json('data.templates'));
        $receipt = $templates->firstWhere('type', 'nota_entrega');
        $this->assertNotNull($receipt);
        $this->assertTrue((bool) $receipt['show_signature']);
        $this->assertFalse((bool) $receipt['show_logo']);

        // Like the delivery note, it never prints the logo: asking for it is ignored.
        $saved = collect($this->actingAs($this->owner, 'sanctum')->putJson('/api/app/print-templates/nota_entrega', [
            'primary_color' => '#112233', 'font_key' => 'geist', 'footer_notes' => 'Signed on delivery.',
            'show_logo' => true, 'show_signature' => true,
        ])->assertOk()->json('data.templates'))->firstWhere('type', 'nota_entrega');
        $this->assertFalse((bool) $saved['show_logo']);
        $this->assertSame('#112233', $saved['primary_color']);

        // Copying the invoice's look onto it brings the colour, never the logo.
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/app/print-templates/factura', [
            'primary_color' => '#445566', 'font_key' => 'geist', 'footer_notes' => '', 'show_logo' => true, 'show_signature' => false,
        ])->assertOk();
        $copied = collect($this->actingAs($this->owner, 'sanctum')->postJson('/api/app/print-templates/factura/copy', ['types' => ['nota_entrega']])
            ->assertOk()->json('data.templates'))->firstWhere('type', 'nota_entrega');
        $this->assertSame('#445566', $copied['primary_color']);
        $this->assertFalse((bool) $copied['show_logo']);
    }

    public function test_it_needs_its_own_permission(): void
    {
        $invoicesOnly = User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id, 'permissions' => ['invoices.view', 'invoices.create']]);
        $viewOnly = User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id, 'permissions' => ['delivery_receipts.view']]);
        $cashier = User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id, 'permissions' => Permissions::preset('cashier')]);

        $this->issue([], $invoicesOnly)->assertForbidden();
        $this->issue([], $viewOnly)->assertForbidden();
        $id = $this->issue([], $cashier)->assertCreated()->json('data.id');

        $this->actingAs($viewOnly, 'sanctum')->getJson("/api/app/sales/{$id}")->assertOk();
        $this->actingAs($invoicesOnly, 'sanctum')->getJson("/api/app/sales/{$id}")->assertForbidden();
        $this->actingAs($viewOnly, 'sanctum')->deleteJson("/api/app/sales/{$id}")->assertForbidden();
    }

    public function test_every_other_kind_still_needs_a_client_name_and_cannot_start_paid_unless_it_takes_payments(): void
    {
        $this->issue(['type' => 'factura'])->assertStatus(422)->assertJsonValidationErrors('client_name');

        $quote = $this->issue(['type' => 'quotation', 'client_name' => 'Ana', 'payment_status' => 'paid'])->assertCreated();
        $this->assertSame('pending', $quote->json('data.payment_status'));
    }
}
