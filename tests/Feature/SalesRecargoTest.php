<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\RecargoRate;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SalesRecargoTest extends TestCase
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

    private function rateId(float $percent): int
    {
        return (int) RecargoRate::query()->where('rate', $percent)->value('id');
    }

    /** 10 x 1000 cents at 21% IVA: base 10000, tax 2100. */
    private function issue(string $type, array $extra = []): TestResponse
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 50]);

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', array_merge([
            'type' => $type,
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'client_name' => 'Ada Client',
            'lines' => [[
                'product_id' => $product->id,
                'article' => $product->article,
                'quantity' => 10,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ], $extra));
    }

    public function test_an_invoice_without_recargo_is_unchanged(): void
    {
        $this->issue('factura')
            ->assertCreated()
            ->assertJsonPath('data.recargo_percent', null)
            ->assertJsonPath('data.recargo_cents', 0)
            ->assertJsonPath('data.total_cents', 12100);
    }

    public function test_the_recargo_is_a_percentage_of_the_taxable_base_and_joins_the_total(): void
    {
        // 5.2% of the 10000 base is 520. Total: 10000 + 2100 + 520.
        $this->issue('factura', ['recargo_rate_id' => $this->rateId(5.2)])
            ->assertCreated()
            ->assertJsonPath('data.base_cents', 10000)
            ->assertJsonPath('data.tax_cents', 2100)
            ->assertJsonPath('data.recargo_percent', 5.2)
            ->assertJsonPath('data.recargo_cents', 520)
            ->assertJsonPath('data.total_cents', 12620);

        $this->assertSame(520, SalesDocument::sole()->recargo_cents);
    }

    public function test_the_recargo_keeps_the_rate_it_had_when_the_invoice_was_issued(): void
    {
        $rate = RecargoRate::query()->where('rate', 1.4)->first();
        $this->issue('factura', ['recargo_rate_id' => $rate->id])->assertCreated();

        $rate->update(['rate' => 9]);

        $this->assertSame(1.4, SalesDocument::sole()->recargo_percent);
        $this->assertSame(140, SalesDocument::sole()->recargo_cents);
    }

    public function test_recargo_is_refused_on_a_delivery_note_and_a_proforma(): void
    {
        foreach (['albaran', 'proforma'] as $type) {
            $this->issue($type, ['recargo_rate_id' => $this->rateId(5.2)])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['recargo_rate_id']);
        }

        $this->assertSame(0, SalesDocument::count());
    }

    public function test_a_quotation_can_carry_recargo_too(): void
    {
        $this->issue('quotation', ['recargo_rate_id' => $this->rateId(5.2)])
            ->assertCreated()
            ->assertJsonPath('data.recargo_percent', 5.2)
            ->assertJsonPath('data.recargo_cents', 520)
            ->assertJsonPath('data.total_cents', 12620);
    }

    public function test_editing_a_quotation_can_change_or_remove_the_recargo(): void
    {
        $id = $this->issue('quotation', ['recargo_rate_id' => $this->rateId(5.2)])->assertCreated()->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->editPayload(['recargo_rate_id' => $this->rateId(1.4)]))
            ->assertOk()
            ->assertJsonPath('data.recargo_percent', 1.4)
            ->assertJsonPath('data.recargo_cents', 140)
            ->assertJsonPath('data.total_cents', 12240);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->editPayload(['recargo_rate_id' => null]))
            ->assertOk()
            ->assertJsonPath('data.recargo_percent', null)
            ->assertJsonPath('data.recargo_cents', 0)
            ->assertJsonPath('data.total_cents', 12100);
    }

    public function test_a_quotation_hands_its_recargo_to_the_invoice_made_from_it(): void
    {
        $id = $this->issue('quotation', ['recargo_rate_id' => $this->rateId(5.2)])->assertCreated()->json('data.id');

        // Even if the rate has since left Settings, the quotation's own rate goes across.
        RecargoRate::query()->where('rate', 5.2)->delete();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", ['type' => 'factura', 'payment_status' => 'pending'])
            ->assertCreated()
            ->assertJsonPath('data.recargo_percent', 5.2)
            ->assertJsonPath('data.recargo_cents', 520)
            ->assertJsonPath('data.total_cents', 12620);
    }

    public function test_converting_to_a_delivery_note_leaves_the_recargo_behind(): void
    {
        $id = $this->issue('quotation', ['recargo_rate_id' => $this->rateId(5.2)])->assertCreated()->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", ['type' => 'albaran', 'payment_status' => 'pending'])
            ->assertCreated()
            ->assertJsonPath('data.recargo_percent', null)
            ->assertJsonPath('data.recargo_cents', 0);
    }

    public function test_a_rate_from_another_company_cannot_be_used(): void
    {
        $other = Company::factory()->create();
        $foreign = RecargoRate::withoutGlobalScopes()->where('company_id', $other->id)->where('rate', 5.2)->value('id');

        $this->issue('factura', ['recargo_rate_id' => $foreign])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recargo_rate_id']);

        $this->assertSame(0, SalesDocument::count());
    }

    public function test_an_amount_or_percent_sent_by_the_client_is_ignored(): void
    {
        $this->issue('factura', [
            'recargo_rate_id' => $this->rateId(0.5),
            'recargo_percent' => 90,
            'recargo_cents' => 99999,
            'total_cents' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.recargo_percent', 0.5)
            ->assertJsonPath('data.recargo_cents', 50)
            ->assertJsonPath('data.total_cents', 12150);
    }

    /** The body of an edit of a quotation: one line, 10 x 1000 at 21% IVA. */
    private function editPayload(array $extra = []): array
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 50]);

        return array_merge([
            'issued_at' => now()->toIso8601String(),
            'client_name' => 'Ada Client',
            'lines' => [[
                'product_id' => $product->id,
                'article' => $product->article,
                'quantity' => 10,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ], $extra);
    }
}
