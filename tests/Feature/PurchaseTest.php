<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PurchaseTest extends TestCase
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

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'quantity' => 10,
            'buying_price' => 500,
            'selling_price' => 900,
        ], $attributes));
    }

    private function payload(array $lines, array $extra = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'purchased_on' => now()->toDateString(),
            'lines' => $lines,
        ], $extra);
    }

    private function member(string $role): User
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [
            'name' => 'Marta Rivas',
            'email' => "{$role}@shop.test",
            'password' => 'secret-pass-1',
            'permissions' => Permissions::preset($role),
        ])->assertCreated();

        return User::where('email', "{$role}@shop.test")->firstOrFail();
    }

    public function test_a_purchase_adds_the_stock_and_makes_the_rate_the_buying_price(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/purchases', $this->payload([
                ['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 450],
            ], ['supplier_invoice_no' => 'FA-77', 'notes' => 'First delivery']))
            ->assertCreated()
            ->assertJsonPath('data.supplier_name', 'Luz del Sur')
            ->assertJsonPath('data.total_cents', 9000)
            ->assertJsonPath('data.supplier_invoice_no', 'FA-77')
            ->assertJsonPath('data.lines.0.article', $product->article)
            ->assertJsonPath('data.lines.0.total_cents', 9000);

        $product->refresh();
        $this->assertSame(30, $product->quantity);
        $this->assertSame(450, $product->buying_price);
        $this->assertSame(500, $product->last_buying_price);
        $this->assertSame(100.0, (float) $product->margin_percent);
    }

    public function test_the_numbers_follow_the_month_of_the_purchase(): void
    {
        $product = $this->product();
        $line = [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100]];

        $first = $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/purchases', $this->payload($line))->assertCreated();
        $second = $this->postJson('/api/app/purchases', $this->payload($line))->assertCreated();

        $period = now()->format('Ym');
        $this->assertSame("PU-{$period}/00001", $first->json('data.number'));
        $this->assertSame("PU-{$period}/00002", $second->json('data.number'));
    }

    public function test_two_lines_for_one_product_add_up_and_the_last_rate_wins(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/purchases', $this->payload([
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 400],
                ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 420],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.total_cents', 5 * 400 + 3 * 420);

        $product->refresh();
        $this->assertSame(18, $product->quantity);
        $this->assertSame(420, $product->buying_price);
    }

    public function test_a_product_or_supplier_of_another_company_is_refused(): void
    {
        $other = Company::factory()->create();
        $theirProduct = Product::factory()->create(['company_id' => $other->id, 'quantity' => 4]);
        $theirSupplier = Supplier::factory()->create(['company_id' => $other->id]);
        $mine = $this->product();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/purchases', $this->payload([['product_id' => $theirProduct->id, 'quantity' => 1, 'unit_cost' => 1]]))
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.product_id');

        $this->postJson('/api/app/purchases', $this->payload([['product_id' => $mine->id, 'quantity' => 1, 'unit_cost' => 1]], ['supplier_id' => $theirSupplier->id]))
            ->assertStatus(422)->assertJsonValidationErrors('supplier_id');

        $this->assertSame(4, $theirProduct->fresh()->quantity);
        $this->assertSame(0, Purchase::withoutGlobalScopes()->count());
    }

    public function test_the_form_is_checked(): void
    {
        $product = $this->product();
        $line = ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100];

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson('/api/app/purchases', $this->payload([]))->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->postJson('/api/app/purchases', $this->payload([[...$line, 'quantity' => 0]]))->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');
        $this->postJson('/api/app/purchases', $this->payload([[...$line, 'unit_cost' => -1]]))->assertStatus(422)->assertJsonValidationErrors('lines.0.unit_cost');
        $this->postJson('/api/app/purchases', $this->payload([$line], ['purchased_on' => now()->addDay()->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('purchased_on');

        $this->assertSame(10, $product->fresh()->quantity);
    }

    public function test_cancelling_takes_the_stock_back_and_keeps_the_record(): void
    {
        $product = $this->product();
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/purchases', $this->payload([['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 450]]))
            ->json('data.id');

        $this->postJson("/api/app/purchases/{$id}/void", ['reason' => 'Wrong supplier'])
            ->assertOk()
            ->assertJsonPath('data.is_voided', true)
            ->assertJsonPath('data.void_reason', 'Wrong supplier');

        $this->assertSame(10, $product->fresh()->quantity);

        $this->postJson("/api/app/purchases/{$id}/void", ['reason' => 'Again'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(10, $product->fresh()->quantity);
    }

    public function test_a_purchase_cannot_be_cancelled_once_its_pieces_were_sold(): void
    {
        $product = $this->product(['quantity' => 0]);
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/purchases', $this->payload([['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 450]]))
            ->json('data.id');

        // 4 of the 5 were sold since.
        $product->refresh()->update(['quantity' => 1]);

        $this->postJson("/api/app/purchases/{$id}/void", ['reason' => 'Mistake'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(1, $product->fresh()->quantity);
        $this->assertFalse(Purchase::find($id)->isVoided());
    }

    public function test_the_list_filters_and_counts_this_month(): void
    {
        $product = $this->product();
        $other = Supplier::factory()->create(['company_id' => $this->company->id, 'name' => 'Norte SL']);
        $line = [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 100]];

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson('/api/app/purchases', $this->payload($line, ['purchased_on' => now()->subMonths(2)->toDateString()]))->assertCreated();
        $this->postJson('/api/app/purchases', $this->payload($line, ['supplier_invoice_no' => 'ABC-1']))->assertCreated();
        $this->postJson('/api/app/purchases', $this->payload($line, ['supplier_id' => $other->id]))->assertCreated();

        $this->getJson('/api/app/purchases')->assertOk()
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.stats.month_count', 2)
            ->assertJsonPath('data.stats.month_cents', 400)
            ->assertJsonPath('data.stats.month_suppliers', 2)
            ->assertJsonPath('data.items.0.items_count', 1);

        $this->getJson('/api/app/purchases?supplier_id='.$other->id)->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/app/purchases?search=ABC-1')->assertJsonPath('data.meta.total', 1);
        $this->getJson('/api/app/purchases?search='.urlencode($product->article))->assertJsonPath('data.meta.total', 3);
        $this->getJson('/api/app/purchases?from='.now()->startOfMonth()->toDateString())->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/app/purchases?to='.now()->subMonth()->toDateString())->assertJsonPath('data.meta.total', 1);
    }

    public function test_the_list_can_carry_the_lines_for_an_export(): void
    {
        $product = $this->product();
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/purchases', $this->payload([
                ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 200],
                ['product_id' => $this->product()->id, 'quantity' => 1, 'unit_cost' => 50],
            ]))
            ->assertCreated();

        $this->getJson('/api/app/purchases')->assertOk()->assertJsonMissingPath('data.items.0.lines');

        // A browser sends true as the word "true"; both spellings work.
        $this->getJson('/api/app/purchases?with_lines=true')->assertOk()->assertJsonCount(2, 'data.items.0.lines');
        $this->getJson('/api/app/purchases?with_lines=false')->assertOk()->assertJsonMissingPath('data.items.0.lines');
        $this->getJson('/api/app/purchases?with_lines=banana')->assertStatus(422)->assertJsonValidationErrors('with_lines');

        $this->getJson('/api/app/purchases?with_lines=1')->assertOk()
            ->assertJsonCount(2, 'data.items.0.lines')
            ->assertJsonPath('data.items.0.lines.0.article', $product->article)
            ->assertJsonPath('data.items.0.lines.0.quantity', 3)
            ->assertJsonPath('data.items.0.lines.0.unit_cost', 200);
    }

    public function test_one_company_never_sees_the_purchases_of_another(): void
    {
        $product = $this->product();
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/purchases', $this->payload([['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100]]))
            ->json('data.id');

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $stranger = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($stranger, 'sanctum')->getJson('/api/app/purchases')->assertJsonPath('data.meta.total', 0);
        $this->getJson("/api/app/purchases/{$id}")->assertNotFound();
        $this->postJson("/api/app/purchases/{$id}/void", ['reason' => 'Not mine'])->assertNotFound();
    }

    public function test_who_may_see_record_and_cancel(): void
    {
        $product = $this->product();
        $line = [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100]];
        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/purchases', $this->payload($line))->json('data.id');

        // The cashier does not work with stock at all.
        $cashier = $this->member('cashier');
        $this->actingAs($cashier, 'sanctum')->getJson('/api/app/purchases')->assertForbidden();

        // The accountant reads the books: can look, cannot record or cancel.
        $accountant = $this->member('accountant');
        $this->actingAs($accountant, 'sanctum')->getJson('/api/app/purchases')->assertOk();
        $this->getJson("/api/app/purchases/{$id}")->assertOk();
        $this->postJson('/api/app/purchases', $this->payload($line))->assertForbidden();
        $this->postJson("/api/app/purchases/{$id}/void", ['reason' => 'Nope'])->assertForbidden();

        // The manager does all three.
        $manager = $this->member('manager');
        $this->actingAs($manager, 'sanctum')->postJson('/api/app/purchases', $this->payload($line))->assertCreated();
        $this->postJson("/api/app/purchases/{$id}/void", ['reason' => 'Mistake'])->assertOk();
    }
}
