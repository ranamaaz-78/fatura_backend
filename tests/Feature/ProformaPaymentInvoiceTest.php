<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Product;
use App\Models\RecargoRate;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaPaymentInvoiceTest extends TestCase
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
        $this->product = Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 10,
            'article' => 'Wholesale box',
            'iva_percent' => 10,
        ]);
    }

    private function proforma(): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', [
                'type' => 'proforma',
                'issued_at' => now()->toIso8601String(),
                'payment_status' => 'pending',
                'client_name' => 'Wholesale client',
                'client_nif' => 'B12345674',
                'lines' => [[
                    'product_id' => $this->product->id,
                    'article' => 'Wholesale box',
                    'quantity' => 4,
                    'unit_price' => 1000,
                    'discount_percent' => 0,
                    'iva_percent' => 0,
                ]],
            ])
            ->assertCreated()
            ->json('data');
    }

    /** Pays for $quantity boxes at $price each and returns the settlement id. */
    private function pay(array $proforma, int $quantity, int $price = 1000): int
    {
        $cash = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Cash')->value('id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$proforma['id']}/settle", [
                'payment_method_id' => $cash,
                'lines' => [['line_id' => $proforma['lines'][0]['id'], 'quantity' => $quantity, 'unit_price' => $price]],
            ])
            ->assertOk();

        return (int) SalesDocument::find($proforma['id'])->settlements()->max('id');
    }

    private function invoiceFor(array $proforma, int $settlementId, array $iva = [])
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$proforma['id']}/settlements/{$settlementId}/invoice", $iva === [] ? [] : ['iva' => $iva]);
    }

    public function test_a_payment_becomes_an_invoice_of_exactly_those_pieces_and_prices(): void
    {
        $proforma = $this->proforma();
        $settlement = $this->pay($proforma, 2, 900);

        $this->invoiceFor($proforma, $settlement)
            ->assertCreated()
            ->assertJsonPath('data.type', 'factura')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.client_name', 'Wholesale Client')
            ->assertJsonPath('data.client_nif', 'B12345674')
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->assertJsonPath('data.lines.0.unit_price', 900)
            // The product's own IVA (10%) is added on top of the 18.00 base.
            ->assertJsonPath('data.lines.0.iva_percent', 10)
            ->assertJsonPath('data.base_cents', 1800)
            ->assertJsonPath('data.tax_cents', 180)
            ->assertJsonPath('data.total_cents', 1980)
            ->assertJsonPath('data.payment_method.name', 'Cash')
            ->assertJsonPath('data.from_proforma.number', $proforma['number']);
    }

    public function test_the_invoice_does_not_take_stock_out_again(): void
    {
        $proforma = $this->proforma();
        $this->assertSame(6, $this->product->fresh()->quantity);

        $this->invoiceFor($proforma, $this->pay($proforma, 2))->assertCreated();

        $this->assertSame(6, $this->product->fresh()->quantity);
    }

    public function test_the_iva_can_be_chosen_per_line(): void
    {
        $proforma = $this->proforma();
        $settlement = $this->pay($proforma, 1);

        $this->invoiceFor($proforma, $settlement, [$proforma['lines'][0]['id'] => 21])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.iva_percent', 21)
            ->assertJsonPath('data.tax_cents', 210);
    }

    public function test_recargo_de_equivalencia_can_be_added_to_the_invoice(): void
    {
        $rate = RecargoRate::withoutGlobalScopes()->where('company_id', $this->company->id)->where('rate', 5.2)->firstOrFail();
        $proforma = $this->proforma();
        $settlement = $this->pay($proforma, 2, 1000);

        // Base 20.00; recargo 5.2% of the base is 1.04, on top of IVA 10% (2.00).
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$proforma['id']}/settlements/{$settlement}/invoice", ['recargo_rate_id' => $rate->id])
            ->assertCreated()
            ->assertJsonPath('data.base_cents', 2000)
            ->assertJsonPath('data.tax_cents', 200)
            ->assertJsonPath('data.recargo_percent', 5.2)
            ->assertJsonPath('data.recargo_cents', 104)
            ->assertJsonPath('data.total_cents', 2304);
    }

    public function test_a_recargo_rate_from_another_company_is_refused(): void
    {
        $other = Company::factory()->create();
        $foreign = RecargoRate::withoutGlobalScopes()->where('company_id', $other->id)->where('rate', 5.2)->firstOrFail();
        $proforma = $this->proforma();
        $settlement = $this->pay($proforma, 1);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$proforma['id']}/settlements/{$settlement}/invoice", ['recargo_rate_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recargo_rate_id');

        // Nothing was issued, so the payment can still be invoiced.
        $this->invoiceFor($proforma, $settlement)->assertCreated();
    }

    public function test_each_payment_is_invoiced_once_and_then_locked(): void
    {
        $proforma = $this->proforma();
        $first = $this->pay($proforma, 1);
        $second = $this->pay($proforma, 1);

        $this->invoiceFor($proforma, $first)->assertCreated();
        $this->invoiceFor($proforma, $first)->assertStatus(422);
        // The other payment is separate and can have its own invoice.
        $this->invoiceFor($proforma, $second)->assertCreated();

        // An invoiced payment can no longer change its quantities.
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$proforma['id']}/settlements/{$first}", [
                'lines' => [['line_id' => $proforma['lines'][0]['id'], 'quantity' => 2]],
            ])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/app/sales/{$proforma['id']}")
            ->assertOk()
            ->assertJsonPath('data.settlements.0.invoice.number', fn ($number) => str_starts_with($number, 'F-'));
    }

    public function test_voiding_the_invoice_keeps_stock_as_it_was_and_frees_the_payment(): void
    {
        $proforma = $this->proforma();
        $settlement = $this->pay($proforma, 2);
        $invoice = $this->invoiceFor($proforma, $settlement)->assertCreated()->json('data');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$invoice['id']}/void", ['reason' => 'Wrong client'])
            ->assertOk();

        // Voiding a normal invoice puts stock back. This one never took any, so nothing moves.
        $this->assertSame(6, $this->product->fresh()->quantity);

        $this->invoiceFor($proforma, $settlement)->assertCreated();
    }

    public function test_the_invoice_does_not_count_as_a_second_sale(): void
    {
        $proforma = $this->proforma();
        $settlement = $this->pay($proforma, 2);

        $before = $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/dashboard')->assertOk()->json('data.kpis');

        $this->invoiceFor($proforma, $settlement)->assertCreated();

        $after = $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/dashboard')->assertOk()->json('data.kpis');
        $this->assertSame($before['paid_this_month'], $after['paid_this_month']);
        $this->assertSame($before['outstanding'], $after['outstanding']);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/payments')->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_the_invoice_still_works_when_the_product_was_deleted(): void
    {
        $proforma = $this->proforma();
        $settlement = $this->pay($proforma, 1);
        $this->product->delete();

        $this->invoiceFor($proforma, $settlement)
            ->assertCreated()
            ->assertJsonPath('data.lines.0.article', 'Wholesale box')
            ->assertJsonPath('data.lines.0.iva_percent', 21);
    }

    public function test_only_a_proforma_payment_of_this_company_can_be_invoiced(): void
    {
        $proforma = $this->proforma();
        $settlement = $this->pay($proforma, 1);

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $stranger = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/app/sales/{$proforma['id']}/settlements/{$settlement}/invoice")
            ->assertNotFound();
    }
}
