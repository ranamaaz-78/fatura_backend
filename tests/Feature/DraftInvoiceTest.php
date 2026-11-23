<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Draft invoices. A draft takes its invoice number at once (the next invoice gets the one after), keeps its date, and
 * takes no stock. Opened again it shows which lines the shelf can no longer cover. Issuing it keeps the number and
 * date, checks and takes the stock, and only then makes it a sale. Deleting it keeps the number as a voided invoice.
 */
class DraftInvoiceTest extends TestCase
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
        $this->product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 10]);
    }

    private function payload(array $extra = [], int $quantity = 4): array
    {
        return array_merge([
            'type' => 'factura',
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'save_customer' => false,
            'client_name' => 'Ana Ruiz',
            'lines' => [[
                'product_id' => $this->product->id,
                'article' => $this->product->article,
                'quantity' => $quantity,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ], $extra);
    }

    private function draft(int $quantity = 4)
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', $this->payload(['draft' => true], $quantity));
    }

    private function cash(): int
    {
        return (int) CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Cash')->value('id');
    }

    public function test_a_draft_takes_its_number_but_no_stock(): void
    {
        $draft = $this->draft()->assertCreated()
            ->assertJsonPath('data.is_draft', true)
            ->assertJsonPath('data.can_issue_draft', true)
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.verify_code', null);

        $this->assertStringStartsWith('F-', $draft->json('data.number'));
        $this->assertSame(10, $this->product->fresh()->quantity);

        // The next invoice takes the number after the draft's.
        $next = $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', $this->payload())->assertCreated();
        $this->assertSame((int) substr($draft->json('data.number'), -5) + 1, (int) substr($next->json('data.number'), -5));
        $this->assertSame(6, $this->product->fresh()->quantity);
    }

    public function test_a_draft_is_no_sale_until_it_is_issued(): void
    {
        $this->draft()->assertCreated();
        $as = fn () => $this->actingAs($this->owner, 'sanctum');

        $as()->getJson('/api/app/dashboard')->assertOk()->assertJsonPath('data.kpis.document_count', 0);
        $as()->getJson('/api/app/reports/sales')->assertOk()->assertJsonPath('data.kpis.document_count', 0);
        $as()->getJson('/api/app/payments')->assertOk()->assertJsonCount(0, 'data.items');

        // It is in the invoice list, under its own filter, and in no money figure.
        $list = $as()->getJson('/api/app/sales?type=factura')->assertOk();
        $list->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.counts.draft', 1)
            ->assertJsonPath('data.counts.pending', 0)
            ->assertJsonPath('data.stats.total_cents', 0);
        $as()->getJson('/api/app/sales?type=factura&display_status=draft')->assertOk()->assertJsonCount(1, 'data.items');
        $as()->getJson('/api/app/sales?type=factura&display_status=pending')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_a_draft_is_edited_without_moving_stock_and_keeps_its_number_and_date(): void
    {
        $draft = $this->draft()->assertCreated();
        $id = $draft->json('data.id');

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/sales/{$id}", array_merge($this->payload([], 7), [
            'issued_at' => now()->addDays(3)->toIso8601String(),
            'client_name' => 'Ana Ruiz Gómez',
        ]))->assertOk()
            ->assertJsonPath('data.is_draft', true)
            ->assertJsonPath('data.number', $draft->json('data.number'))
            ->assertJsonPath('data.issued_at', $draft->json('data.issued_at'))
            ->assertJsonPath('data.lines.0.quantity', 7)
            ->assertJsonPath('data.client_name', 'Ana Ruiz Gómez');

        $this->assertSame(10, $this->product->fresh()->quantity);
    }

    public function test_opening_a_draft_again_shows_the_lines_the_stock_no_longer_covers(): void
    {
        $id = $this->draft(6)->assertCreated()->json('data.id');

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/app/sales/{$id}")->assertOk()->assertJsonPath('data.stock_shortages', []);

        // Another invoice sells most of the stock meanwhile.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', $this->payload([], 7))->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/app/sales/{$id}")->assertOk()
            ->assertJsonPath('data.stock_shortages.0.position', 1)
            ->assertJsonPath('data.stock_shortages.0.needed', 6)
            ->assertJsonPath('data.stock_shortages.0.available', 3);
    }

    public function test_issuing_keeps_the_number_and_date_and_takes_the_stock_now(): void
    {
        $draft = $this->draft()->assertCreated();
        $id = $draft->json('data.id');

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$id}/issue-draft", ['payment_status' => 'paid', 'payment_method_id' => $this->cash()])
            ->assertOk()
            ->assertJsonPath('data.is_draft', false)
            ->assertJsonPath('data.number', $draft->json('data.number'))
            ->assertJsonPath('data.issued_at', $draft->json('data.issued_at'))
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.verify_code', fn ($code) => is_string($code) && strlen($code) === 24);

        $this->assertSame(6, $this->product->fresh()->quantity);

        // Now it is a sale, and it cannot be issued twice.
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/dashboard')->assertOk()->assertJsonPath('data.kpis.document_count', 1);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$id}/issue-draft", ['payment_status' => 'pending'])->assertStatus(422);
        $this->assertSame(6, $this->product->fresh()->quantity);
    }

    public function test_it_is_not_issued_when_the_stock_falls_short(): void
    {
        $id = $this->draft(6)->assertCreated()->json('data.id');
        $this->product->update(['quantity' => 2]);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$id}/issue-draft", ['payment_status' => 'pending'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');

        $this->assertSame(2, $this->product->fresh()->quantity);
        $this->assertTrue(SalesDocument::query()->findOrFail($id)->isDraft());

        // Fixed (fewer pieces), it goes through.
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/sales/{$id}", $this->payload([], 2))->assertOk();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$id}/issue-draft", ['payment_status' => 'pending'])->assertOk();
        $this->assertSame(0, $this->product->fresh()->quantity);
    }

    public function test_deleting_a_draft_keeps_its_number_as_a_voided_invoice(): void
    {
        $draft = $this->draft()->assertCreated();
        $id = $draft->json('data.id');

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/app/sales/{$id}")->assertOk()
            ->assertJsonPath('data.is_voided', true)
            ->assertJsonPath('data.number', $draft->json('data.number'))
            ->assertJsonPath('data.is_deleted', false);

        $document = SalesDocument::query()->findOrFail($id);
        $this->assertNotNull($document->voided_at);
        $this->assertNotEmpty($document->void_reason);
        $this->assertSame(10, $this->product->fresh()->quantity);

        // It is a record now: never edited, issued, deleted again, nor in the bin.
        $as = fn () => $this->actingAs($this->owner, 'sanctum');
        $as()->patchJson("/api/app/sales/{$id}", $this->payload())->assertStatus(422);
        $as()->postJson("/api/app/sales/{$id}/issue-draft", ['payment_status' => 'pending'])->assertStatus(422);
        $as()->deleteJson("/api/app/sales/{$id}")->assertStatus(422);
        $as()->getJson('/api/app/sales/trash-counts')->assertOk()->assertJsonPath('data.factura', 0);

        // The numbering has no gap: the next invoice comes after the voided draft.
        $next = $as()->postJson('/api/app/sales', $this->payload())->assertCreated();
        $this->assertSame((int) substr($draft->json('data.number'), -5) + 1, (int) substr($next->json('data.number'), -5));
    }

    public function test_only_an_invoice_is_drafted_and_a_draft_is_never_paid(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', $this->payload(['type' => 'albaran', 'draft' => true]))
            ->assertStatus(422)->assertJsonValidationErrors('draft');

        $id = $this->draft()->assertCreated()->json('data.id');
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/sales/{$id}/payment", ['payment_status' => 'paid', 'payment_method_id' => $this->cash()])
            ->assertStatus(422);

        // Asking for "paid" on the draft itself is ignored: it is paid when issued.
        $paidDraft = $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', $this->payload(['draft' => true, 'payment_status' => 'paid', 'payment_method_id' => $this->cash()]))
            ->assertCreated();
        $this->assertSame('pending', $paidDraft->json('data.payment_status'));
    }

    public function test_a_drafts_qr_code_does_not_verify_until_it_is_issued(): void
    {
        $id = $this->draft()->assertCreated()->json('data.id');
        $code = SalesDocument::query()->findOrFail($id)->verify_code;

        $this->getJson("/api/public/invoices/verify/{$code}")->assertNotFound();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$id}/issue-draft", ['payment_status' => 'pending'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/public/invoices/verify/{$code}")->assertOk();
    }

    public function test_issuing_a_draft_needs_permission_to_create_invoices(): void
    {
        $id = $this->draft()->assertCreated()->json('data.id');
        $viewer = User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id, 'permissions' => ['invoices.view']]);

        $this->actingAs($viewer, 'sanctum')->postJson("/api/app/sales/{$id}/issue-draft", ['payment_status' => 'pending'])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->patchJson("/api/app/sales/{$id}", $this->payload())->assertForbidden();
        $this->assertSame(10, $this->product->fresh()->quantity);
    }
}
