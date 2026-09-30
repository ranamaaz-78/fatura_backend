<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\RecargoRate;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SaleMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SalesBillDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    /**
     * @param  array<int, array{price: int, qty?: int, iva?: float, discount?: float}>|null  $rows
     */
    private function issue(string $type, array $extra = [], ?array $rows = null): TestResponse
    {
        $rows ??= [['price' => 1000, 'qty' => 10, 'iva' => 21]];

        $lines = array_map(function (array $row) {
            $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 500]);

            return [
                'product_id' => $product->id,
                'article' => $product->article,
                'quantity' => $row['qty'] ?? 1,
                'unit_price' => $row['price'],
                'discount_percent' => $row['discount'] ?? 0,
                'iva_percent' => $row['iva'] ?? 21,
            ];
        }, $rows);

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', array_merge([
            'type' => $type,
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'client_name' => 'Ada Client',
            'lines' => $lines,
        ], $extra));
    }

    public function test_the_allocation_adds_up_to_exactly_the_discount(): void
    {
        $this->assertSame([3, 3, 4], SaleMath::allocate([333, 333, 334], 10));
        $this->assertSame([0, 0, 0], SaleMath::allocate([100, 200, 300], 0));
        $this->assertSame([100, 200, 300], SaleMath::allocate([100, 200, 300], 600));

        foreach ([1, 7, 99, 1234, 99999] as $discount) {
            $shares = SaleMath::allocate([1999, 3, 555, 12345, 1], $discount);
            $this->assertSame($discount, array_sum($shares));
        }
    }

    public function test_a_percentage_discount_comes_off_the_base_and_iva_follows_it(): void
    {
        // Base 10000. 10% off = 1000, so base 9000, IVA 21% = 1890, total 10890.
        $this->issue('factura', ['discount_type' => 'percent', 'discount_value' => 10])
            ->assertCreated()
            ->assertJsonPath('data.discount_type', 'percent')
            ->assertJsonPath('data.discount_value', 10)
            ->assertJsonPath('data.discount_cents', 1000)
            ->assertJsonPath('data.base_cents', 9000)
            ->assertJsonPath('data.tax_cents', 1890)
            ->assertJsonPath('data.total_cents', 10890)
            ->assertJsonPath('data.lines.0.bill_discount_cents', 1000)
            ->assertJsonPath('data.lines.0.base_cents', 9000);
    }

    public function test_a_fixed_amount_discount_works_the_same_way(): void
    {
        // 25.00 off the 100.00 base: base 7500, IVA 1575, total 9075.
        $this->issue('factura', ['discount_type' => 'amount', 'discount_value' => 2500])
            ->assertCreated()
            ->assertJsonPath('data.discount_type', 'amount')
            ->assertJsonPath('data.discount_cents', 2500)
            ->assertJsonPath('data.base_cents', 7500)
            ->assertJsonPath('data.tax_cents', 1575)
            ->assertJsonPath('data.total_cents', 9075);
    }

    public function test_the_discount_reaches_every_line_and_every_iva_rate(): void
    {
        // Base 6000 at 21% and 4000 at 10%. A 1000 discount shares out 600 and 400.
        $response = $this->issue('factura', ['discount_type' => 'amount', 'discount_value' => 1000], [
            ['price' => 3000, 'qty' => 2, 'iva' => 21],
            ['price' => 2000, 'qty' => 2, 'iva' => 10],
        ])->assertCreated();

        $response->assertJsonPath('data.lines.0.bill_discount_cents', 600)
            ->assertJsonPath('data.lines.1.bill_discount_cents', 400)
            // 5400 at 21% = 1134, and 3600 at 10% = 360.
            ->assertJsonPath('data.lines.0.tax_cents', 1134)
            ->assertJsonPath('data.lines.1.tax_cents', 360)
            ->assertJsonPath('data.base_cents', 9000)
            ->assertJsonPath('data.tax_cents', 1494)
            ->assertJsonPath('data.total_cents', 10494);

        $document = SalesDocument::sole();
        $this->assertSame($document->base_cents, (int) $document->lines->sum('base_cents'));
        $this->assertSame($document->tax_cents, (int) $document->lines->sum('tax_cents'));
    }

    public function test_the_discount_stacks_on_line_discounts(): void
    {
        // Line: 10 x 1000 less 10% = 9000. Bill discount 10% = 900. Base 8100, IVA 1701.
        $this->issue('factura', ['discount_type' => 'percent', 'discount_value' => 10], [
            ['price' => 1000, 'qty' => 10, 'iva' => 21, 'discount' => 10],
        ])
            ->assertCreated()
            ->assertJsonPath('data.discount_cents', 900)
            ->assertJsonPath('data.base_cents', 8100)
            ->assertJsonPath('data.tax_cents', 1701)
            ->assertJsonPath('data.total_cents', 9801);
    }

    public function test_recargo_is_worked_out_on_the_discounted_base(): void
    {
        $rate = (int) RecargoRate::query()->where('rate', 5.2)->value('id');

        // Base 9000 after 10% off. Recargo 5.2% of 9000 = 468. Total 9000 + 1890 + 468.
        $this->issue('factura', ['discount_type' => 'percent', 'discount_value' => 10, 'recargo_rate_id' => $rate])
            ->assertCreated()
            ->assertJsonPath('data.recargo_cents', 468)
            ->assertJsonPath('data.total_cents', 11358);
    }

    public function test_a_zero_or_missing_discount_changes_nothing(): void
    {
        $this->issue('factura', ['discount_type' => 'percent', 'discount_value' => 0])
            ->assertCreated()
            ->assertJsonPath('data.discount_type', null)
            ->assertJsonPath('data.discount_cents', 0)
            ->assertJsonPath('data.total_cents', 12100);

        $this->issue('factura')
            ->assertCreated()
            ->assertJsonPath('data.lines.0.bill_discount_cents', 0)
            ->assertJsonPath('data.total_cents', 12100);
    }

    public function test_a_discount_can_be_the_whole_bill(): void
    {
        $this->issue('factura', ['discount_type' => 'percent', 'discount_value' => 100])
            ->assertCreated()
            ->assertJsonPath('data.base_cents', 0)
            ->assertJsonPath('data.tax_cents', 0)
            ->assertJsonPath('data.total_cents', 0);
    }

    public function test_a_discount_larger_than_the_bill_is_refused(): void
    {
        $this->issue('factura', ['discount_type' => 'amount', 'discount_value' => 10001])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount_value']);

        $this->issue('factura', ['discount_type' => 'percent', 'discount_value' => 101])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount_value']);

        $this->issue('factura', ['discount_type' => 'percent', 'discount_value' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount_value']);

        $this->assertSame(0, SalesDocument::count());
    }

    public function test_a_discount_needs_a_value_and_a_known_type(): void
    {
        $this->issue('factura', ['discount_type' => 'percent'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount_value']);

        $this->issue('factura', ['discount_type' => 'bogus', 'discount_value' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount_type']);
    }

    public function test_it_applies_to_invoices_delivery_notes_and_quotations_but_not_proformas(): void
    {
        $this->issue('albaran', ['discount_type' => 'percent', 'discount_value' => 10])
            ->assertCreated()
            ->assertJsonPath('data.discount_cents', 1000)
            ->assertJsonPath('data.total_cents', 9000);

        $this->issue('quotation', ['discount_type' => 'percent', 'discount_value' => 10])
            ->assertCreated()
            ->assertJsonPath('data.discount_cents', 1000);

        $this->issue('proforma', ['discount_type' => 'percent', 'discount_value' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount_type']);
    }

    public function test_a_discount_sent_as_an_amount_by_the_client_cannot_be_forged(): void
    {
        $this->issue('factura', ['discount_type' => 'percent', 'discount_value' => 10, 'discount_cents' => 9999, 'total_cents' => 1])
            ->assertCreated()
            ->assertJsonPath('data.discount_cents', 1000)
            ->assertJsonPath('data.total_cents', 10890);
    }

    public function test_a_quotation_keeps_its_discount_when_edited_and_when_converted(): void
    {
        $id = $this->issue('quotation', ['discount_type' => 'percent', 'discount_value' => 10])
            ->assertCreated()
            ->json('data.id');

        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 500]);

        // Edit: a fixed 5.00 discount instead.
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", [
                'issued_at' => now()->toIso8601String(),
                'client_name' => 'Ada Client',
                'discount_type' => 'amount',
                'discount_value' => 500,
                'lines' => [[
                    'product_id' => $product->id,
                    'article' => $product->article,
                    'quantity' => 10,
                    'unit_price' => 1000,
                    'discount_percent' => 0,
                    'iva_percent' => 21,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.discount_type', 'amount')
            ->assertJsonPath('data.discount_cents', 500)
            ->assertJsonPath('data.total_cents', 11495);

        // Convert to an invoice: the discount goes with it.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", ['type' => 'factura', 'payment_status' => 'pending'])
            ->assertCreated()
            ->assertJsonPath('data.discount_type', 'amount')
            ->assertJsonPath('data.discount_cents', 500)
            ->assertJsonPath('data.total_cents', 11495);
    }
}
