<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupplierLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Supplier $supplier;

    private CompanyPaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
        $this->supplier = Supplier::factory()->create(['company_id' => $this->company->id, 'name' => 'Luz del Sur']);
        $this->cash = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('sort_order')->firstOrFail();
    }

    /** An entry of 100 000. */
    private function entry(array $extra = [])
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/ledger', array_merge([
            'supplier_id' => $this->supplier->id,
            'bill_date' => now()->toDateString(),
            'description' => '40 LED lamps and 2 cartons of cable',
            'amount_cents' => 100000,
        ], $extra));
    }

    private function pay(int $id, int $amount, array $extra = [])
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/ledger/{$id}/payments", array_merge([
            'amount_cents' => $amount,
            'paid_on' => now()->toDateString(),
            'payment_method_id' => $this->cash->id,
        ], $extra));
    }

    public function test_an_entry_records_the_supplier_a_description_and_an_amount(): void
    {
        $month = now()->format('Ym');

        $this->entry(['reference' => 'LS-2041'])->assertCreated()
            ->assertJsonPath('data.number', "SB-{$month}/00001")
            ->assertJsonPath('data.supplier_name', 'Luz del Sur')
            ->assertJsonPath('data.description', '40 LED lamps and 2 cartons of cable')
            ->assertJsonPath('data.reference', 'LS-2041')
            ->assertJsonPath('data.amount_cents', 100000)
            ->assertJsonPath('data.paid_cents', 0)
            ->assertJsonPath('data.owed_cents', 100000)
            ->assertJsonPath('data.payment_status', 'unpaid');
    }

    public function test_it_is_a_record_book_only_and_never_touches_products_stock_or_purchases(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 7, 'buying_price' => 500]);

        $this->entry(['paid_cents' => 40000, 'payment_method_id' => $this->cash->id])->assertCreated();

        $this->assertSame(7, $product->fresh()->quantity);
        $this->assertSame(500, (int) $product->fresh()->buying_price);
        $this->assertSame(0, Purchase::withoutGlobalScopes()->count());

        // And Purchases never show up in the ledger.
        $this->getJson('/api/app/ledger')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_it_can_be_paid_in_full_when_it_is_written_down(): void
    {
        $this->entry(['paid_cents' => 100000, 'payment_method_id' => $this->cash->id])->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.owed_cents', 0)
            ->assertJsonCount(1, 'data.payments');
    }

    public function test_half_paid_now_and_the_rest_in_two_instalments(): void
    {
        $id = $this->entry(['paid_cents' => 50000, 'payment_method_id' => $this->cash->id])->assertCreated()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.owed_cents', 50000)
            ->json('data.id');

        $this->pay($id, 30000)->assertCreated()->assertJsonPath('data.payment_status', 'partial')->assertJsonPath('data.owed_cents', 20000);
        $this->pay($id, 20000)->assertCreated()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.owed_cents', 0);

        $this->getJson("/api/app/ledger/{$id}")->assertOk()->assertJsonCount(3, 'data.payments');
    }

    public function test_nothing_can_be_paid_beyond_what_is_owed(): void
    {
        $id = $this->entry(['paid_cents' => 90000, 'payment_method_id' => $this->cash->id])->json('data.id');

        $this->pay($id, 10001)->assertStatus(422)->assertJsonValidationErrors('amount_cents');
        $this->pay($id, 10000)->assertCreated()->assertJsonPath('data.payment_status', 'paid');
        $this->pay($id, 1)->assertStatus(422)->assertJsonValidationErrors('amount_cents');

        $this->entry(['paid_cents' => 100001, 'payment_method_id' => $this->cash->id])->assertStatus(422)->assertJsonValidationErrors('paid_cents');
    }

    public function test_a_payment_needs_a_method_of_the_company_and_a_sensible_date(): void
    {
        $id = $this->entry()->json('data.id');
        $theirs = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', Company::factory()->create()->id)->firstOrFail();

        $this->pay($id, 1000, ['payment_method_id' => null])->assertStatus(422);
        $this->pay($id, 1000, ['payment_method_id' => $theirs->id])->assertStatus(422)->assertJsonValidationErrors('payment_method_id');
        $this->pay($id, 1000, ['paid_on' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('paid_on');
        $this->pay($id, 1000, ['paid_on' => now()->subDays(3)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('paid_on');
    }

    public function test_the_form_is_checked(): void
    {
        $this->entry(['description' => ''])->assertStatus(422)->assertJsonValidationErrors('description');
        $this->entry(['amount_cents' => 0])->assertStatus(422)->assertJsonValidationErrors('amount_cents');
        $this->entry(['bill_date' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('bill_date');
        $this->entry(['supplier_id' => Supplier::factory()->create()->id])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
    }

    public function test_removing_a_payment_opens_the_balance_again_and_keeps_the_record(): void
    {
        $id = $this->entry()->json('data.id');
        $paymentId = $this->pay($id, 100000)->assertJsonPath('data.payment_status', 'paid')->json('data.payments.0.id');

        $this->deleteJson("/api/app/ledger/{$id}/payments/{$paymentId}")->assertOk()
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.owed_cents', 100000)
            ->assertJsonCount(0, 'data.payments');

        $this->assertSoftDeleted('supplier_bill_payments', ['id' => $paymentId]);
        $this->deleteJson("/api/app/ledger/{$id}/payments/{$paymentId}")->assertNotFound();
    }

    public function test_an_entry_with_payments_cannot_be_cancelled_until_they_are_removed(): void
    {
        $id = $this->entry()->json('data.id');
        $paymentId = $this->pay($id, 40000)->json('data.payments.0.id');

        $this->postJson("/api/app/ledger/{$id}/void", ['reason' => 'Mistake'])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->deleteJson("/api/app/ledger/{$id}/payments/{$paymentId}")->assertOk();
        $this->postJson("/api/app/ledger/{$id}/void", ['reason' => 'Mistake'])->assertOk()->assertJsonPath('data.is_voided', true);

        // A cancelled entry is not owed and cannot be paid.
        $this->pay($id, 1000)->assertStatus(422);
    }

    public function test_the_list_filters_by_payment_and_counts_what_is_owed(): void
    {
        $unpaid = $this->entry()->json('data.id');
        $partial = $this->entry(['paid_cents' => 25000, 'payment_method_id' => $this->cash->id])->json('data.id');
        $paid = $this->entry(['paid_cents' => 100000, 'payment_method_id' => $this->cash->id])->json('data.id');
        $voided = $this->entry()->json('data.id');
        $this->postJson("/api/app/ledger/{$voided}/void", ['reason' => 'Mistake'])->assertOk();

        $ids = fn (string $payment) => collect($this->getJson("/api/app/ledger?payment={$payment}")->assertOk()->json('data.items'))->pluck('id')->all();

        $this->assertSame([$unpaid], $ids('unpaid'));
        $this->assertSame([$partial], $ids('partial'));
        $this->assertSame([$paid], $ids('paid'));

        // 100 000 + 75 000 still owed; the cancelled one is not counted.
        $this->getJson('/api/app/ledger')
            ->assertJsonPath('data.stats.owed_cents', 175000)
            ->assertJsonPath('data.stats.open_count', 2)
            ->assertJsonPath('data.stats.month_bought_cents', 300000)
            ->assertJsonPath('data.stats.month_paid_cents', 125000);

        $this->getJson('/api/app/ledger?search=cartons&status=active')->assertJsonCount(3, 'data.items');
        $this->getJson('/api/app/ledger?search=nothing-like-this')->assertJsonCount(0, 'data.items');
    }

    public function test_each_supplier_shows_what_was_bought_paid_and_owed(): void
    {
        $other = Supplier::factory()->create(['company_id' => $this->company->id, 'name' => 'Electro Hogar']);
        $this->entry(['paid_cents' => 30000, 'payment_method_id' => $this->cash->id])->assertCreated();
        $this->entry()->assertCreated();
        $this->entry(['supplier_id' => $other->id, 'paid_cents' => 100000, 'payment_method_id' => $this->cash->id])->assertCreated();

        $rows = collect($this->getJson('/api/app/ledger/suppliers')->assertOk()->json('data'))->keyBy('supplier_name');
        $this->assertSame(['Luz del Sur', 'Electro Hogar'], $rows->keys()->all(), 'who is owed most comes first');
        $this->assertSame(200000, $rows['Luz del Sur']['bought_cents']);
        $this->assertSame(30000, $rows['Luz del Sur']['paid_cents']);
        $this->assertSame(170000, $rows['Luz del Sur']['owed_cents']);
        $this->assertSame(0, $rows['Electro Hogar']['owed_cents']);

        $this->getJson("/api/app/ledger/suppliers/{$this->supplier->id}")->assertOk()
            ->assertJsonPath('data.bought_cents', 200000)
            ->assertJsonPath('data.owed_cents', 170000)
            ->assertJsonCount(2, 'data.entries');
    }

    public function test_one_company_never_sees_or_pays_the_entries_of_another(): void
    {
        $id = $this->entry()->json('data.id');

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $stranger = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($stranger, 'sanctum')->getJson('/api/app/ledger')->assertJsonCount(0, 'data.items');
        $this->getJson("/api/app/ledger/{$id}")->assertNotFound();
        $this->postJson("/api/app/ledger/{$id}/payments", ['amount_cents' => 1000, 'paid_on' => now()->toDateString(), 'payment_method_id' => $this->cash->id])->assertNotFound();
    }

    public function test_who_may_use_the_ledger(): void
    {
        $id = $this->entry()->json('data.id');

        foreach (['cashier', 'accountant', 'manager'] as $role) {
            $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [
                'name' => 'Marta Rivas',
                'email' => "{$role}@shop.test",
                'password' => 'secret-pass-1',
                'permissions' => Permissions::preset($role),
            ])->assertCreated();
        }

        $payload = ['amount_cents' => 1000, 'paid_on' => now()->toDateString(), 'payment_method_id' => $this->cash->id];

        // The cashier has nothing to do with it.
        $cashier = User::where('email', 'cashier@shop.test')->firstOrFail();
        $this->actingAs($cashier, 'sanctum')->getJson('/api/app/ledger')->assertForbidden();

        // The accountant reads the books: can look, cannot write anything.
        $accountant = User::where('email', 'accountant@shop.test')->firstOrFail();
        $this->actingAs($accountant, 'sanctum')->getJson('/api/app/ledger')->assertOk();
        $this->getJson("/api/app/ledger/{$id}")->assertOk();
        $this->postJson('/api/app/ledger', ['supplier_id' => $this->supplier->id, 'bill_date' => now()->toDateString(), 'description' => 'Nope', 'amount_cents' => 100])->assertForbidden();
        $this->postJson("/api/app/ledger/{$id}/payments", $payload)->assertForbidden();
        $this->postJson("/api/app/ledger/{$id}/void", ['reason' => 'Nope'])->assertForbidden();

        // The manager does all of it.
        $manager = User::where('email', 'manager@shop.test')->firstOrFail();
        $this->actingAs($manager, 'sanctum')->postJson("/api/app/ledger/{$id}/payments", $payload)->assertCreated();
        $this->postJson('/api/app/ledger', ['supplier_id' => $this->supplier->id, 'bill_date' => now()->toDateString(), 'description' => 'Second', 'amount_cents' => 100])->assertCreated();
    }

    public function test_the_list_shows_when_each_entry_was_last_paid_and_how_many_payments(): void
    {
        $id = $this->entry(['paid_cents' => 20000, 'payment_method_id' => $this->cash->id])->json('data.id');
        $this->pay($id, 10000)->assertCreated();

        $row = collect($this->getJson('/api/app/ledger')->assertOk()->json('data.items'))->firstWhere('id', $id);
        $this->assertSame(2, $row['payments_count']);
        $this->assertSame(now()->toDateString(), $row['last_paid_on']);

        $this->getJson("/api/app/ledger/{$id}")->assertJsonPath('data.last_paid_on', now()->toDateString());
    }

    public function test_a_supplier_account_is_a_ledger_with_an_opening_balance_and_a_running_balance(): void
    {
        $this->entry(['bill_date' => now()->subDays(40)->toDateString(), 'amount_cents' => 100000, 'description' => 'Old order'])->assertCreated();
        $second = $this->entry(['bill_date' => now()->subDays(10)->toDateString(), 'amount_cents' => 50000, 'description' => 'New order'])->json('data.id');
        $this->pay($second, 20000, ['paid_on' => now()->subDays(5)->toDateString()])->assertCreated();

        // The whole history: opens at zero, ends at what is owed.
        $all = $this->getJson("/api/app/ledger/suppliers/{$this->supplier->id}")->assertOk();
        $all->assertJsonPath('data.opening_cents', 0)
            ->assertJsonPath('data.closing_cents', 130000)
            ->assertJsonPath('data.bought_cents', 150000)
            ->assertJsonPath('data.paid_cents', 20000);
        $this->assertSame([100000, 150000, 130000], collect($all->json('data.transactions'))->pluck('balance_cents')->all());
        $this->assertSame(['bill', 'bill', 'payment'], collect($all->json('data.transactions'))->pluck('type')->all());

        // The last 20 days: the old order is the opening balance, and the running balance starts from it.
        $range = $this->getJson("/api/app/ledger/suppliers/{$this->supplier->id}?from=".now()->subDays(20)->toDateString().'&to='.now()->toDateString())->assertOk();
        $range->assertJsonPath('data.opening_cents', 100000)->assertJsonPath('data.closing_cents', 130000)->assertJsonPath('data.bought_cents', 50000)->assertJsonPath('data.paid_cents', 20000);
        $this->assertSame([150000, 130000], collect($range->json('data.transactions'))->pluck('balance_cents')->all());

        // A range that ends before the second order leaves only the old one.
        $early = $this->getJson("/api/app/ledger/suppliers/{$this->supplier->id}?to=".now()->subDays(30)->toDateString())->assertOk();
        $this->assertSame([100000], collect($early->json('data.transactions'))->pluck('balance_cents')->all());

        $this->getJson("/api/app/ledger/suppliers/{$this->supplier->id}?from=2026-02-02&to=2026-01-01")->assertStatus(422);
    }

    public function test_the_supplier_summary_has_the_last_purchase_last_payment_and_oldest_open_entry(): void
    {
        $this->entry(['bill_date' => now()->subDays(30)->toDateString(), 'amount_cents' => 100000])->assertCreated();
        $second = $this->entry(['bill_date' => now()->subDays(3)->toDateString(), 'amount_cents' => 40000])->json('data.id');
        $this->pay($second, 40000)->assertCreated();

        $row = $this->getJson('/api/app/ledger/suppliers')->assertOk()->json('data.0');
        $this->assertSame(now()->subDays(3)->toDateString(), $row['last_bill_date']);
        $this->assertSame(now()->subDays(30)->toDateString(), $row['oldest_open_on'], 'the paid one is not open');
        $this->assertSame(now()->toDateString(), $row['last_paid_on']);
    }
}
