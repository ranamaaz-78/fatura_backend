<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PurchaseLedgerSyncTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
        $this->supplier = Supplier::factory()->create(['company_id' => $this->company->id, 'name' => 'Luz del Sur']);
    }

    private function purchase(array $extra = [], int $cost = 10000, int $quantity = 5)
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 0, 'article' => 'LED lamp']);

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/purchases', array_merge([
            'supplier_id' => $this->supplier->id,
            'purchased_on' => now()->toDateString(),
            'supplier_invoice_no' => 'LS-2041',
            'notes' => 'Delivered on Tuesday',
            'lines' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost]],
        ], $extra));
    }

    public function test_a_purchase_writes_itself_down_in_supplier_payments(): void
    {
        $id = $this->purchase()->assertCreated()->json('data.id');

        $entry = SupplierBill::withoutGlobalScopes()->where('purchase_id', $id)->firstOrFail();
        $this->assertSame($this->supplier->id, $entry->supplier_id);
        $this->assertSame('Luz del Sur', $entry->supplier_name);
        $this->assertSame(50000, $entry->amount_cents, '5 pieces at 10 000');
        $this->assertSame(0, $entry->paid_cents);
        $this->assertSame('LS-2041', $entry->reference);
        $this->assertSame('Delivered on Tuesday', $entry->notes);
        $this->assertSame(now()->toDateString(), $entry->bill_date->toDateString());
        $this->assertStringContainsString('LED lamp x5', $entry->description);
        $this->assertStringContainsString(Purchase::withoutGlobalScopes()->find($id)->number, $entry->description);
        $this->assertStringStartsWith('SB-', $entry->number);
    }

    public function test_the_two_screens_show_each_other(): void
    {
        $id = $this->purchase()->json('data.id');
        $number = Purchase::withoutGlobalScopes()->find($id)->number;

        // Supplier Payments knows which purchase an entry came from...
        $row = $this->getJson('/api/app/ledger')->assertOk()->json('data.items.0');
        $this->assertSame($id, $row['purchase_id']);
        $this->assertSame($number, $row['purchase_number']);

        // ...and the purchase shows where it stands there, once it is paid in part.
        $entryId = $row['id'];
        $cash = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('sort_order')->firstOrFail();
        $this->postJson("/api/app/ledger/{$entryId}/payments", ['amount_cents' => 20000, 'paid_on' => now()->toDateString(), 'payment_method_id' => $cash->id])->assertCreated();

        $this->getJson("/api/app/purchases/{$id}")->assertOk()
            ->assertJsonPath('data.ledger_entry.id', $entryId)
            ->assertJsonPath('data.ledger_entry.payment_status', 'partial')
            ->assertJsonPath('data.ledger_entry.owed_cents', 30000);
    }

    public function test_the_entry_counts_in_what_is_owed_to_the_supplier(): void
    {
        $this->purchase()->assertCreated();
        $this->purchase()->assertCreated();

        $row = collect($this->getJson('/api/app/ledger/suppliers')->assertOk()->json('data'))->firstWhere('supplier_name', 'Luz del Sur');
        $this->assertSame(100000, $row['owed_cents']);
        $this->getJson('/api/app/ledger')->assertJsonPath('data.stats.owed_cents', 100000);
    }

    public function test_a_purchase_that_costs_nothing_writes_nothing(): void
    {
        $this->purchase([], 0)->assertCreated();

        $this->assertSame(0, SupplierBill::withoutGlobalScopes()->count());
    }

    public function test_cancelling_the_purchase_cancels_its_entry(): void
    {
        $id = $this->purchase()->json('data.id');

        $this->postJson("/api/app/purchases/{$id}/void", ['reason' => 'Wrong supplier'])->assertOk();

        $entry = SupplierBill::withoutGlobalScopes()->where('purchase_id', $id)->firstOrFail();
        $this->assertTrue($entry->isVoided());
        $this->getJson('/api/app/ledger')->assertJsonPath('data.stats.owed_cents', 0);
    }

    public function test_a_purchase_cannot_be_cancelled_while_its_entry_has_payments(): void
    {
        $id = $this->purchase()->json('data.id');
        $entryId = SupplierBill::withoutGlobalScopes()->where('purchase_id', $id)->value('id');
        $cash = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('sort_order')->firstOrFail();
        $paymentId = $this->postJson("/api/app/ledger/{$entryId}/payments", ['amount_cents' => 1000, 'paid_on' => now()->toDateString(), 'payment_method_id' => $cash->id])->json('data.payments.0.id');

        $this->postJson("/api/app/purchases/{$id}/void", ['reason' => 'Mistake'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertNull(Purchase::withoutGlobalScopes()->find($id)->voided_at, 'nothing was changed');

        $this->deleteJson("/api/app/ledger/{$entryId}/payments/{$paymentId}")->assertOk();
        $this->postJson("/api/app/purchases/{$id}/void", ['reason' => 'Mistake'])->assertOk();
    }

    public function test_the_stock_is_the_purchases_own_and_the_entry_does_not_touch_it(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 3]);
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchased_on' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 500]],
        ])->assertCreated();

        $this->assertSame(7, $product->fresh()->quantity);

        // Cancelling the entry on its own (in Supplier Payments) leaves the purchase and its stock as they are.
        $entryId = SupplierBill::withoutGlobalScopes()->value('id');
        $this->postJson("/api/app/ledger/{$entryId}/void", ['reason' => 'Entered twice'])->assertOk();
        $this->assertSame(7, $product->fresh()->quantity);
        $this->assertNull(Purchase::withoutGlobalScopes()->first()->voided_at);
    }

    public function test_the_sync_command_writes_the_older_purchases_once(): void
    {
        $id = $this->purchase()->json('data.id');
        // A purchase made before the two were joined: its entry is missing.
        SupplierBill::withoutGlobalScopes()->where('purchase_id', $id)->delete();
        $this->assertSame(0, SupplierBill::withoutGlobalScopes()->count());

        $this->artisan('ledger:sync-purchases')->assertSuccessful();
        $this->assertSame(1, SupplierBill::withoutGlobalScopes()->where('purchase_id', $id)->count());

        // Run again: nothing is written twice.
        $this->artisan('ledger:sync-purchases')->assertSuccessful();
        $this->assertSame(1, SupplierBill::withoutGlobalScopes()->count());
    }

    public function test_the_entry_shows_the_iva_of_its_purchase_on_the_list_the_entry_and_the_account(): void
    {
        // 5 pieces at 100.00 before IVA, 21% on top: 500.00 + 105.00 = 605.00 owed.
        $id = $this->purchase(['iva_mode' => 'excluded', 'iva_percent' => 21])->assertCreated()->json('data.id');
        $entry = SupplierBill::withoutGlobalScopes()->where('purchase_id', $id)->firstOrFail();
        $this->assertSame(60500, $entry->amount_cents);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/ledger')
            ->assertJsonPath('data.items.0.iva.percent', 21)
            ->assertJsonPath('data.items.0.iva.base_cents', 50000)
            ->assertJsonPath('data.items.0.iva.iva_cents', 10500);

        $this->getJson("/api/app/ledger/{$entry->id}")->assertJsonPath('data.iva.mode', 'excluded');

        $this->getJson("/api/app/ledger/suppliers/{$this->supplier->id}")
            ->assertJsonPath('data.iva_cents', 10500)
            ->assertJsonPath('data.transactions.0.iva_cents', 10500);
    }

    public function test_an_entry_without_iva_says_so(): void
    {
        $this->purchase()->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/ledger')->assertJsonPath('data.items.0.iva', null);
    }

    public function test_a_purchase_is_changed_while_nothing_is_paid_and_the_stock_and_entry_follow(): void
    {
        $created = $this->purchase([], 10000, 5)->assertCreated();
        $id = $created->json('data.id');
        $productId = $created->json('data.lines.0.product_id');
        $this->assertSame(5, Product::withoutGlobalScopes()->find($productId)->quantity);

        $this->getJson("/api/app/purchases/{$id}")->assertJsonPath('data.can_edit', true);

        // 5 pieces become 3 at 120.00, with 21% IVA on top: 360.00 + 75.60 = 435.60.
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/purchases/{$id}", [
            'supplier_id' => $this->supplier->id,
            'purchased_on' => now()->toDateString(),
            'supplier_invoice_no' => 'LS-2041-B',
            'iva_mode' => 'excluded',
            'iva_percent' => 21,
            'lines' => [['product_id' => $productId, 'quantity' => 3, 'unit_cost' => 12000]],
        ])->assertOk()->assertJsonPath('data.total_cents', 43560)->assertJsonPath('data.lines.0.quantity', 3);

        $product = Product::withoutGlobalScopes()->find($productId);
        $this->assertSame(3, $product->quantity, 'the two pieces taken off left the stock');
        $this->assertSame(12000, $product->buying_price);

        $entry = SupplierBill::withoutGlobalScopes()->where('purchase_id', $id)->firstOrFail();
        $this->assertSame(43560, $entry->amount_cents);
        $this->assertSame('LS-2041-B', $entry->reference);
        $this->assertStringContainsString('LED lamp x3', $entry->description);
    }

    public function test_once_something_is_paid_the_purchase_can_no_longer_be_changed(): void
    {
        $created = $this->purchase()->assertCreated();
        $id = $created->json('data.id');
        $entry = SupplierBill::withoutGlobalScopes()->where('purchase_id', $id)->firstOrFail();
        $method = \App\Models\CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->value('id');

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/ledger/{$entry->id}/payments", [
            'amount_cents' => 1000, 'paid_on' => now()->toDateString(), 'payment_method_id' => $method,
        ])->assertCreated();

        $this->getJson("/api/app/purchases/{$id}")->assertJsonPath('data.can_edit', false);
        $this->patchJson("/api/app/purchases/{$id}", [
            'supplier_id' => $this->supplier->id,
            'purchased_on' => now()->toDateString(),
            'lines' => [['product_id' => $created->json('data.lines.0.product_id'), 'quantity' => 1, 'unit_cost' => 100]],
        ])->assertStatus(422);
    }

    public function test_pieces_already_sold_cannot_be_taken_off_a_purchase(): void
    {
        $created = $this->purchase([], 10000, 5)->assertCreated();
        $productId = $created->json('data.lines.0.product_id');
        Product::withoutGlobalScopes()->whereKey($productId)->update(['quantity' => 1]);

        $this->actingAs($this->owner, 'sanctum')->patchJson('/api/app/purchases/'.$created->json('data.id'), [
            'supplier_id' => $this->supplier->id,
            'purchased_on' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'quantity' => 2, 'unit_cost' => 10000]],
        ])->assertStatus(422);

        $this->assertSame(1, Product::withoutGlobalScopes()->find($productId)->quantity);
    }
}
