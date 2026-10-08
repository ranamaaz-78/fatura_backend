<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaReturnTest extends TestCase
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
        $this->product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 10, 'article' => 'Wholesale box']);
    }

    /** A proforma of 4 boxes at 10.00 each. */
    private function proforma(): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', [
                'type' => 'proforma',
                'issued_at' => now()->toIso8601String(),
                'payment_status' => 'pending',
                'client_name' => 'Wholesale client',
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

    private function giveBack(array $proforma, int $quantity, ?string $note = null)
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$proforma['id']}/returns", [
                'lines' => [['line_id' => $proforma['lines'][0]['id'], 'quantity' => $quantity]],
                'note' => $note,
            ]);
    }

    private function pay(array $proforma, int $quantity, int $price = 1000)
    {
        $cash = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->value('id');

        return $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$proforma['id']}/settle", [
                'payment_method_id' => $cash,
                'lines' => [['line_id' => $proforma['lines'][0]['id'], 'quantity' => $quantity, 'unit_price' => $price]],
            ]);
    }

    public function test_returned_pieces_go_back_into_stock_and_stop_counting_as_owed(): void
    {
        $proforma = $this->proforma();
        $this->assertSame(6, $this->product->fresh()->quantity);

        $this->giveBack($proforma, 1, 'Box was damaged')
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.returned_cents', 1000)
            ->assertJsonPath('data.lines.0.returned_quantity', 1)
            ->assertJsonPath('data.lines.0.remaining_quantity', 3)
            ->assertJsonPath('data.returns.0.note', 'Box was damaged')
            ->assertJsonPath('data.returns.0.total_cents', 1000);

        $this->assertSame(7, $this->product->fresh()->quantity);
    }

    public function test_paying_and_returning_together_finish_the_proforma(): void
    {
        $proforma = $this->proforma();

        $this->pay($proforma, 2)->assertOk()->assertJsonPath('data.payment_status', 'partial');
        $this->giveBack($proforma, 1)->assertCreated()->assertJsonPath('data.payment_status', 'partial');

        // One piece is still open: pay for it and the proforma is done.
        $this->pay($proforma, 1)->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.lines.0.remaining_quantity', 0);

        // Nothing is left to return or pay.
        $this->giveBack($proforma, 1)->assertStatus(422);
        $this->pay($proforma, 1)->assertStatus(422);
    }

    public function test_only_the_pieces_not_yet_paid_or_returned_can_come_back(): void
    {
        $proforma = $this->proforma();
        $this->pay($proforma, 3)->assertOk();

        $this->giveBack($proforma, 2)->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');
        $this->giveBack($proforma, 0)->assertStatus(422);
        $this->giveBack($proforma, 1)->assertCreated()->assertJsonPath('data.payment_status', 'paid');

        // Returned pieces also shrink what a later payment may cover.
        $second = $this->proforma();
        $this->giveBack($second, 3)->assertCreated();
        $this->pay($second, 2)->assertStatus(422);
        $this->pay($second, 1)->assertOk()->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_a_proforma_returned_whole_is_marked_as_returned_and_owes_nothing(): void
    {
        $proforma = $this->proforma();

        $this->giveBack($proforma, 4)->assertCreated()
            ->assertJsonPath('data.is_fully_returned', true)
            ->assertJsonPath('data.settled_cents', 0)
            ->assertJsonPath('data.returned_cents', 4000);

        $this->assertSame(10, $this->product->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/payments')->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_returned_pieces_are_not_counted_as_outstanding_money(): void
    {
        $proforma = $this->proforma();

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/dashboard')->assertOk()
            ->assertJsonPath('data.kpis.outstanding', 4000);

        $this->giveBack($proforma, 1)->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/dashboard')->assertOk()
            ->assertJsonPath('data.kpis.outstanding', 3000);
    }

    public function test_a_return_can_be_cancelled_and_the_pieces_leave_stock_again(): void
    {
        $proforma = $this->proforma();
        $returnId = $this->giveBack($proforma, 2)->assertCreated()->json('data.returns.0.id');
        $this->assertSame(8, $this->product->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/sales/{$proforma['id']}/returns/{$returnId}")
            ->assertOk()
            ->assertJsonPath('data.returned_cents', 0)
            ->assertJsonPath('data.lines.0.remaining_quantity', 4)
            ->assertJsonCount(0, 'data.returns');

        $this->assertSame(6, $this->product->fresh()->quantity);
    }

    public function test_a_return_cannot_be_cancelled_when_the_stock_is_already_sold(): void
    {
        $proforma = $this->proforma();
        $returnId = $this->giveBack($proforma, 2)->assertCreated()->json('data.returns.0.id');

        // The two returned boxes were sold on elsewhere.
        $this->product->forceFill(['quantity' => 1])->save();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/sales/{$proforma['id']}/returns/{$returnId}")
            ->assertStatus(422);

        $this->assertSame(1, $this->product->fresh()->quantity);
    }

    public function test_only_a_proforma_can_have_pieces_returned(): void
    {
        $invoice = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', [
                'type' => 'factura',
                'issued_at' => now()->toIso8601String(),
                'payment_status' => 'pending',
                'client_name' => 'Counter sale',
                'lines' => [['article' => 'Item', 'quantity' => 1, 'unit_price' => 500, 'discount_percent' => 0, 'iva_percent' => 21]],
            ])->json('data');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$invoice['id']}/returns", ['lines' => [['line_id' => $invoice['lines'][0]['id'], 'quantity' => 1]]])
            ->assertStatus(422);
    }

    public function test_another_company_cannot_return_pieces_of_this_proforma(): void
    {
        $proforma = $this->proforma();
        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $stranger = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/app/sales/{$proforma['id']}/returns", ['lines' => [['line_id' => $proforma['lines'][0]['id'], 'quantity' => 1]]])
            ->assertNotFound();
    }
}
