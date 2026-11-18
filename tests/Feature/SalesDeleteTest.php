<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesDeleteTest extends TestCase
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
        $this->product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 20, 'article' => 'Denim']);
    }

    private function cash(): int
    {
        return (int) CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('sort_order')->value('id');
    }

    private function make(string $type, array $extra = [], int $quantity = 3)
    {
        $paid = ($extra['payment_status'] ?? 'pending') === 'paid';

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', array_merge([
            'type' => $type,
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'client_name' => 'Walk-in',
            'save_customer' => false,
            'lines' => [[
                'product_id' => $this->product->id,
                'article' => 'Denim',
                'quantity' => $quantity,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => $type === 'albaran' || $type === 'proforma' ? 0 : 21,
            ]],
        ], $paid ? ['payment_method_id' => $this->cash()] : [], $extra));
    }

    private function id(string $type, array $extra = [], int $quantity = 3): int
    {
        return (int) $this->make($type, $extra, $quantity)->assertCreated()->json('data.id');
    }

    private function stock(): int
    {
        return (int) $this->product->fresh()->quantity;
    }

    private function removeDoc(int $id)
    {
        return $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/app/sales/{$id}");
    }

    private function restoreDoc(int $id)
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$id}/restore");
    }

    // ------------------------------------------------------------------ invoices

    public function test_an_unpaid_invoice_is_deleted_softly_and_its_stock_comes_back(): void
    {
        $id = $this->id('factura');
        $this->assertSame(17, $this->stock());

        $this->removeDoc($id)->assertOk()->assertJsonPath('data.is_deleted', true);

        $this->assertSame(20, $this->stock(), 'the pieces are back on the shelf');
        $this->assertNotNull(SalesDocument::withoutGlobalScopes()->withTrashed()->find($id), 'the row is kept');
        $this->assertNull(SalesDocument::find($id), 'but it is out of every normal query');
        $this->getJson('/api/app/sales?type=factura')->assertJsonCount(0, 'data.items')->assertJsonPath('data.counts.all', 0);
    }

    public function test_a_paid_invoice_can_never_be_deleted(): void
    {
        $id = $this->id('factura', ['payment_status' => 'paid']);

        $this->removeDoc($id)->assertStatus(422)->assertJsonPath('message', 'A paid invoice cannot be deleted. Void it instead.');

        $this->assertNotNull(SalesDocument::find($id));
        $this->assertSame(17, $this->stock(), 'nothing moved');
    }

    public function test_a_voided_invoice_cannot_be_deleted_either(): void
    {
        $id = $this->id('factura', ['payment_status' => 'paid']);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$id}/void", ['reason' => 'Mistake'])->assertOk();

        $this->removeDoc($id)->assertStatus(422);
    }

    public function test_the_number_of_a_deleted_invoice_is_never_used_again(): void
    {
        $month = now()->format('Ym');
        $first = $this->id('factura');
        $this->removeDoc($first)->assertOk();

        $this->make('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00002");
    }

    public function test_a_deleted_invoice_disappears_from_reports_the_dashboard_payments_and_the_client(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $kept = $this->id('factura', ['payment_status' => 'paid']);
        $gone = $this->id('factura', ['customer_id' => $customer->id]);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/reports/sales')->assertJsonPath('data.kpis.document_count', 2);
        $this->removeDoc($gone)->assertOk();

        $this->getJson('/api/app/reports/sales')->assertJsonPath('data.kpis.document_count', 1);
        $this->getJson('/api/app/reports/outstanding')->assertJsonPath('data.kpis.document_count', 0);
        $this->getJson('/api/app/reports/sold')->assertJsonPath('data.kpis.quantity', 3);
        $this->getJson('/api/app/dashboard')->assertOk();
        $this->getJson('/api/app/customers')->assertJsonPath('data.0.documents_count', 0);
        $this->assertNotNull(SalesDocument::find($kept));
    }

    // ------------------------------------------------------------------ delivery notes

    public function test_an_unpaid_delivery_note_can_be_deleted_and_restocks(): void
    {
        $id = $this->id('albaran');
        $this->assertSame(17, $this->stock());

        $this->removeDoc($id)->assertOk();
        $this->assertSame(20, $this->stock());
    }

    public function test_a_paid_delivery_note_cannot_be_deleted(): void
    {
        $id = $this->id('albaran', ['payment_status' => 'paid']);

        $this->removeDoc($id)->assertStatus(422)->assertJsonPath('message', 'A paid delivery note cannot be deleted. Void it instead.');
    }

    public function test_a_delivery_note_with_an_invoice_is_refused_until_the_invoice_is_deleted_and_then_it_is_free_again(): void
    {
        $albaran = $this->id('albaran');
        $invoice = (int) $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$albaran}/invoice", [])->assertCreated()->json('data.id');

        $this->removeDoc($albaran)->assertStatus(422)->assertJsonPath('message', 'This delivery note already has an invoice. Delete the invoice first.');

        // The invoice (paper only: it moved no stock) goes; the delivery note can be invoiced again, and deleted.
        $this->removeDoc($invoice)->assertOk();
        $this->assertSame(17, $this->stock(), 'an invoice made from a delivery note never touched the stock');
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/app/sales/{$albaran}")->assertJsonPath('data.is_converted', false);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$albaran}/invoice", [])->assertCreated();
    }

    public function test_restoring_the_invoice_links_it_to_its_delivery_note_again_unless_it_was_invoiced_meanwhile(): void
    {
        $albaran = $this->id('albaran');
        $invoice = (int) $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$albaran}/invoice", [])->assertCreated()->json('data.id');
        $this->removeDoc($invoice)->assertOk();

        // Restore straight away: the delivery note stands under it again.
        $this->restoreDoc($invoice)->assertOk()->assertJsonPath('data.is_deleted', false);
        $this->assertNotNull(SalesDocument::find($albaran)->converted_to_id);
        $this->removeDoc($albaran)->assertStatus(422);

        // Delete it again, invoice the delivery note a second time: the first invoice can no longer come back.
        $this->removeDoc($invoice)->assertOk();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$albaran}/invoice", [])->assertCreated();
        $this->restoreDoc($invoice)->assertStatus(422);
    }

    // ------------------------------------------------------------------ quotations

    public function test_an_open_or_expired_quotation_can_be_deleted_and_moves_no_stock(): void
    {
        $open = $this->id('quotation');
        $this->assertSame(20, $this->stock());
        $this->removeDoc($open)->assertOk();
        $this->assertSame(20, $this->stock());

        $old = $this->id('quotation');
        SalesDocument::whereKey($old)->update(['expires_at' => now()->subDay()]);
        $this->removeDoc($old)->assertOk();
    }

    public function test_a_converted_quotation_is_refused_until_the_document_made_from_it_is_deleted(): void
    {
        $quote = $this->id('quotation', [], 2);
        $invoice = (int) $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$quote}/convert", ['type' => 'factura', 'payment_status' => 'pending'])->assertCreated()->json('data.id');
        $this->assertSame(18, $this->stock());

        $this->removeDoc($quote)->assertStatus(422);

        // Deleting the invoice puts the stock back and re-opens the quotation: it can be edited and converted again.
        $this->removeDoc($invoice)->assertOk();
        $this->assertSame(20, $this->stock());
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/app/sales/{$quote}")->assertJsonPath('data.is_converted', false);
        $this->removeDoc($quote)->assertOk();
    }

    // ------------------------------------------------------------------ proformas

    public function test_a_proforma_with_no_payment_and_no_return_can_be_deleted(): void
    {
        $id = $this->id('proforma', [], 4);
        $this->assertSame(16, $this->stock());

        $this->removeDoc($id)->assertOk();
        $this->assertSame(20, $this->stock());
    }

    public function test_a_proforma_with_a_payment_or_a_return_cannot_be_deleted(): void
    {
        $paid = $this->make('proforma', [], 4)->assertCreated();
        $lineId = $paid->json('data.lines.0.id');
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales/'.$paid->json('data.id').'/settle', [
            'payment_method_id' => $this->cash(),
            'lines' => [['line_id' => $lineId, 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertOk();

        $this->removeDoc((int) $paid->json('data.id'))->assertStatus(422)->assertJsonPath('message', 'A proforma with payments or returns cannot be deleted.');
    }

    // ------------------------------------------------------------------ restore

    public function test_a_deleted_document_is_seen_in_the_deleted_list_and_can_be_restored(): void
    {
        $id = $this->id('factura');
        $this->removeDoc($id)->assertOk();

        $this->getJson('/api/app/sales?type=factura&trashed=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $id)
            ->assertJsonPath('data.items.0.is_deleted', true)
            ->assertJsonPath('data.items.0.deleted_by_name', $this->owner->name)
            ->assertJsonPath('data.counts.trashed', 1);
        $this->getJson("/api/app/sales/{$id}")->assertOk()->assertJsonPath('data.is_deleted', true);

        $this->assertSame(20, $this->stock());
        $this->restoreDoc($id)->assertOk()->assertJsonPath('data.is_deleted', false);
        $this->assertSame(17, $this->stock(), 'it takes its stock out again');
        $this->getJson('/api/app/sales?type=factura')->assertJsonCount(1, 'data.items');
        $this->getJson('/api/app/sales?type=factura&trashed=1')->assertJsonCount(0, 'data.items');
    }

    public function test_a_restore_is_refused_when_the_stock_is_no_longer_there(): void
    {
        $id = $this->id('factura', [], 5);
        $this->removeDoc($id)->assertOk();

        $this->product->forceFill(['quantity' => 2])->save();

        $this->restoreDoc($id)->assertStatus(422)->assertJsonPath('message', 'Not enough stock for Denim. Only 2 left.');
        $this->assertSame(2, $this->stock(), 'nothing was taken');
        $this->assertNull(SalesDocument::find($id), 'and it is still deleted');
    }

    public function test_a_document_that_is_not_deleted_cannot_be_restored_or_deleted_twice(): void
    {
        $id = $this->id('factura');

        $this->restoreDoc($id)->assertStatus(422);
        $this->removeDoc($id)->assertOk();
        $this->removeDoc($id)->assertNotFound();
    }

    // ------------------------------------------------------------------ who

    public function test_who_may_delete(): void
    {
        $id = $this->id('factura');

        foreach (['cashier', 'accountant', 'manager'] as $role) {
            $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [
                'name' => 'Marta Rivas',
                'email' => "{$role}@shop.test",
                'password' => 'secret-pass-1',
                'permissions' => Permissions::preset($role),
            ])->assertCreated();
        }

        $cashier = User::where('email', 'cashier@shop.test')->firstOrFail();
        $accountant = User::where('email', 'accountant@shop.test')->firstOrFail();
        $manager = User::where('email', 'manager@shop.test')->firstOrFail();

        $this->actingAs($cashier, 'sanctum')->deleteJson("/api/app/sales/{$id}")->assertForbidden();
        $this->actingAs($accountant, 'sanctum')->deleteJson("/api/app/sales/{$id}")->assertForbidden();
        // They see no Deleted list either.
        $this->actingAs($accountant, 'sanctum')->getJson('/api/app/sales?type=factura&trashed=1')->assertOk()->assertJsonCount(0, 'data.items');

        $this->actingAs($manager, 'sanctum')->deleteJson("/api/app/sales/{$id}")->assertOk();
        $this->actingAs($cashier, 'sanctum')->postJson("/api/app/sales/{$id}/restore")->assertForbidden();
        $this->actingAs($manager, 'sanctum')->postJson("/api/app/sales/{$id}/restore")->assertOk();
    }

    public function test_the_permission_data_migration_gives_the_delete_tick_to_managers_only(): void
    {
        // A manager-level member saved before the tick existed, and a cashier.
        $manager = User::factory()->create(['role' => UserRole::Staff, 'company_id' => $this->company->id, 'permissions' => ['invoices.view', 'invoices.void', 'quotes.update', 'proformas.create']]);
        $cashier = User::factory()->create(['role' => UserRole::Staff, 'company_id' => $this->company->id, 'permissions' => ['invoices.view', 'invoices.create', 'quotes.create']]);

        (include database_path('migrations/2026_11_16_000200_add_document_delete_permissions.php'))->up();

        $this->assertEqualsCanonicalizing(['invoices.view', 'invoices.void', 'quotes.update', 'proformas.create', 'invoices.delete', 'quotes.delete', 'proformas.delete'], $manager->fresh()->permissions);
        $this->assertEqualsCanonicalizing(['invoices.view', 'invoices.create', 'quotes.create'], $cashier->fresh()->permissions);
    }

    public function test_another_company_can_neither_delete_nor_restore(): void
    {
        $id = $this->id('factura');

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $stranger = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/app/sales/{$id}")->assertNotFound();
        $this->removeDoc($id)->assertOk();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/app/sales/{$id}/restore")->assertNotFound();
    }

    // ------------------------------------------------------------------ the public code

    public function test_the_code_printed_on_a_deleted_invoice_reads_as_cancelled(): void
    {
        $invoice = $this->make('factura')->assertCreated();
        $code = $invoice->json('data.verify_code');

        $this->getJson("/api/public/invoices/verify/{$code}")->assertOk()->assertJsonPath('data.voided', false);

        $this->removeDoc((int) $invoice->json('data.id'))->assertOk();

        $this->getJson("/api/public/invoices/verify/{$code}")->assertOk()->assertJsonPath('data.voided', true)->assertJsonPath('data.deleted', true);
        $this->getJson("/api/public/invoices/verify/{$code}/invoice")->assertNotFound();
    }

    public function test_an_opened_document_says_whether_it_can_be_deleted_and_why_not(): void
    {
        $free = $this->id('factura');
        $paid = $this->id('factura', ['payment_status' => 'paid']);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/app/sales/{$free}")->assertJsonPath('data.can_delete', true)->assertJsonPath('data.delete_blocked', null);
        $this->getJson("/api/app/sales/{$paid}")->assertJsonPath('data.can_delete', false)->assertJsonPath('data.delete_blocked', 'A paid invoice cannot be deleted. Void it instead.');
    }

    public function test_many_documents_are_deleted_and_restored_at_once_with_the_refused_ones_named(): void
    {
        $a = $this->id('factura');
        $b = $this->id('factura');
        $paid = $this->id('factura', ['payment_status' => 'paid']);

        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales/bulk-delete', ['ids' => [$a, $b, $paid]])->assertOk();
        $this->assertEqualsCanonicalizing([$a, $b], $response->json('data.done'));
        $this->assertSame($paid, $response->json('data.failed.0.id'));
        $this->assertSame('A paid invoice cannot be deleted. Void it instead.', $response->json('data.failed.0.reason'));
        $this->assertSame(17, $this->stock() - 0 === 17 ? 17 : $this->stock(), 'only the paid one still holds its stock');

        $this->getJson('/api/app/sales/trash-counts')->assertJsonPath('data.factura', 2)->assertJsonPath('data.albaran', 0);

        $this->postJson('/api/app/sales/bulk-restore', ['ids' => [$a, $b]])->assertOk()->assertJsonCount(2, 'data.done')->assertJsonCount(0, 'data.failed');
        $this->getJson('/api/app/sales?type=factura')->assertJsonCount(3, 'data.items')->assertJsonPath('data.items.0.can_delete', fn ($v) => is_bool($v));
    }
}
