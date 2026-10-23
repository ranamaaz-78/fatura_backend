<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
use App\Support\IssueDate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesDocumentTest extends TestCase
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

    public function test_each_document_kind_gets_its_own_running_number(): void
    {
        $month = now()->format('Ym');

        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00001");
        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00002");
        $this->issue('albaran')->assertCreated()->assertJsonPath('data.number', "AL-{$month}/00001");
        $this->issue('quotation')->assertCreated()->assertJsonPath('data.number', "Q-{$month}/00001");
        $this->issue('proforma')->assertCreated()->assertJsonPath('data.number', "PF-{$month}/00001");
    }

    public function test_only_invoices_carry_a_verification_code_and_anyone_holding_it_can_verify_them(): void
    {
        $invoice = $this->issue('factura')->assertCreated();
        $code = $invoice->json('data.verify_code');

        $this->assertSame(24, strlen($code));
        $this->assertNull($this->issue('albaran')->json('data.verify_code'));
        $this->assertNull($this->issue('quotation')->json('data.verify_code'));
        $this->assertNull($this->issue('proforma')->json('data.verify_code'));

        // No login: this is what the QR code on the printed invoice opens.
        $this->getJson("/api/public/invoices/verify/{$code}")
            ->assertOk()
            ->assertJsonPath('data.number', $invoice->json('data.number'))
            ->assertJsonPath('data.voided', false)
            ->assertJsonPath('data.company.name', $this->company->name)
            ->assertJsonMissingPath('data.client_name')
            ->assertJsonMissingPath('data.customer');

        $this->getJson('/api/public/invoices/verify/does-not-exist-123456')->assertNotFound();
        $this->getJson('/api/public/invoices/verify/short')->assertNotFound();
    }

    public function test_the_whole_invoice_can_be_opened_with_its_code_and_without_a_login(): void
    {
        $created = $this->issue('factura')->assertCreated();
        $code = $created->json('data.verify_code');

        $this->getJson("/api/public/invoices/verify/{$code}/document")
            ->assertOk()
            ->assertJsonPath('data.document.number', $created->json('data.number'))
            ->assertJsonPath('data.document.type', 'factura')
            ->assertJsonStructure(['data' => ['document' => ['lines'], 'company' => ['name', 'tax_id', 'currency'], 'template' => ['primary_color', 'font_key'], 'locale']])
            // Nothing internal about the company leaves the server.
            ->assertJsonMissingPath('data.company.notes')
            ->assertJsonMissingPath('data.company.status')
            ->assertJsonMissingPath('data.company.owner');

        $this->getJson('/api/public/invoices/verify/not-a-real-code-123456/document')->assertNotFound();

        // Only invoices open this way: a delivery note's id or number gives nothing.
        $albaran = $this->issue('albaran')->assertCreated();
        $this->getJson('/api/public/invoices/verify/'.str_repeat('a', 24).'/document')->assertNotFound();
        $this->assertNull($albaran->json('data.verify_code'));
    }

    public function test_a_voided_invoice_is_reported_as_voided_when_scanned(): void
    {
        $created = $this->issue('factura')->assertCreated();
        $document = SalesDocument::withoutGlobalScopes()->findOrFail($created->json('data.id'));
        $document->forceFill(['voided_at' => now(), 'void_reason' => 'Mistake'])->save();

        $this->getJson('/api/public/invoices/verify/'.$created->json('data.verify_code'))
            ->assertOk()
            ->assertJsonPath('data.voided', true);
    }

    public function test_an_abono_can_no_longer_be_issued(): void
    {
        $this->issue('abono')->assertStatus(422);

        $this->assertSame(0, SalesDocument::count());
    }

    public function test_the_line_total_discounts_the_net_price_and_then_adds_iva(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 20,
            'article' => 'Thermal roll',
        ]);

        // 4 x 370 cents, 10% off, then 21% IVA: base 1332, tax 280, total 1612.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('factura', [[
                'product_id' => $product->id,
                'article' => 'Thermal roll',
                'quantity' => 4,
                'unit_price' => 370,
                'discount_percent' => 10,
                'iva_percent' => 21,
            ]]))
            ->assertCreated()
            ->assertJsonPath('data.base_cents', 1332)
            ->assertJsonPath('data.tax_cents', 280)
            ->assertJsonPath('data.total_cents', 1612)
            ->assertJsonPath('data.lines.0.total_cents', 1612);

        $this->assertSame(16, $product->fresh()->quantity);
    }

    public function test_a_sale_cannot_take_more_stock_than_there_is(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 1,
            'article' => 'HDMI cable',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('factura', [[
                'product_id' => $product->id,
                'article' => 'HDMI cable',
                'quantity' => 2,
                'unit_price' => 900,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Not enough stock for HDMI cable. Only 1 left.');

        $this->assertSame(1, $product->fresh()->quantity);
        $this->assertSame(0, SalesDocument::count());
    }

    public function test_a_quotation_leaves_the_stock_alone(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 5]);

        $this->issue('quotation', $product, 2)->assertCreated();

        $this->assertSame(5, $product->fresh()->quantity);
    }

    public function test_a_delivery_note_takes_stock_and_drops_the_iva(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 5]);

        $this->issue('albaran', $product, 2)
            ->assertCreated()
            ->assertJsonPath('data.tax_cents', 0)
            ->assertJsonPath('data.lines.0.iva_percent', 0)
            ->assertJsonPath('data.total_cents', 2000);

        $this->assertSame(3, $product->fresh()->quantity);
    }

    public function test_a_proforma_takes_stock_and_keeps_the_typed_price_whole(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 5,
            'article' => 'Agreed item',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('proforma', [[
                'product_id' => $product->id,
                'article' => 'Agreed item',
                'quantity' => 2,
                'unit_price' => 1000,
                'discount_percent' => 25,
                'iva_percent' => 21,
            ]]))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.discount_percent', 0)
            ->assertJsonPath('data.lines.0.iva_percent', 0)
            ->assertJsonPath('data.tax_cents', 0)
            ->assertJsonPath('data.total_cents', 2000);

        $this->assertSame(3, $product->fresh()->quantity);
    }

    public function test_saving_a_client_gives_them_the_next_code_and_keeps_a_snapshot(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('factura', client: [
                'save_customer' => true,
                'client_name' => 'Ana Ruiz',
                'client_company' => 'Ruiz SL',
                'client_phone' => '612345678',
                'client_nif' => '12345678Z',
                'client_nie' => 'Y1234567X',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.client_code', 'C-0001')
            ->assertJsonPath('data.client_company', 'Ruiz SL')
            ->assertJsonPath('data.client_nie', 'Y1234567X');

        $this->assertSame(1, Customer::count());

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/customers?search=ruiz')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'C-0001')
            ->assertJsonPath('data.0.nif', '12345678Z');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/sales/'.$response->json('data.id').'/payment', [
                'payment_status' => 'paid',
                'payment_method_id' => $this->cashId(),
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method.name', 'Cash');
    }

    public function test_a_paid_invoice_needs_an_active_payment_method(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('factura', payment: [
                'payment_status' => 'paid',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.payment_method_id.0', 'Pick how this was paid.');

        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('factura', payment: [
                'payment_status' => 'paid',
                'payment_method_id' => $this->cashId(),
            ]))
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method.name', 'Cash')
            ->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}/payment", ['payment_status' => 'pending'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A paid document cannot go back to pending. Void it instead.');
    }

    public function test_voiding_a_paid_invoice_puts_the_stock_back(): void
    {
        $roll = Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 10,
            'article' => 'Thermal roll',
        ]);
        $cable = Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 4,
            'article' => 'HDMI cable',
        ]);

        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('factura', [
                [
                    'product_id' => $roll->id,
                    'article' => 'Thermal roll',
                    'quantity' => 3,
                    'unit_price' => 370,
                    'discount_percent' => 0,
                    'iva_percent' => 21,
                ],
                [
                    'product_id' => $cable->id,
                    'article' => 'HDMI cable',
                    'quantity' => 1,
                    'unit_price' => 900,
                    'discount_percent' => 0,
                    'iva_percent' => 21,
                ],
                [
                    'article' => 'Walk-in item',
                    'quantity' => 1,
                    'unit_price' => 200,
                    'discount_percent' => 0,
                    'iva_percent' => 21,
                ],
            ], payment: [
                'payment_status' => 'paid',
                'payment_method_id' => $this->cashId(),
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(7, $roll->fresh()->quantity);
        $this->assertSame(3, $cable->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/void", ['reason' => 'Client returned the order'])
            ->assertOk()
            ->assertJsonPath('data.is_voided', true)
            ->assertJsonPath('data.void_reason', 'Client returned the order')
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertSame(10, $roll->fresh()->quantity);
        $this->assertSame(4, $cable->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/void", ['reason' => 'Trying again'])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}/payment", [
                'payment_status' => 'paid',
                'payment_method_id' => $this->cashId(),
            ])
            ->assertStatus(422);
    }

    public function test_a_paid_delivery_note_can_be_voided_and_a_pending_one_cannot(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 8]);
        $pending = $this->issue('albaran', $product, 2)->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$pending}/void", ['reason' => 'Wrong client'])
            ->assertStatus(422);

        $this->assertSame(6, $product->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$pending}/payment", [
                'payment_status' => 'paid',
                'payment_method_id' => $this->cashId(),
            ])
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$pending}/void", ['reason' => 'Goods came back'])
            ->assertOk()
            ->assertJsonPath('data.is_voided', true);

        $this->assertSame(8, $product->fresh()->quantity);
    }

    public function test_a_quotation_cannot_be_voided(): void
    {
        $id = $this->issue('quotation')->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/void", ['reason' => 'Changed our mind'])
            ->assertStatus(422);
    }

    public function test_void_needs_a_reason(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('factura', payment: [
                'payment_status' => 'paid',
                'payment_method_id' => $this->cashId(),
            ]))
            ->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/void", ['reason' => ''])
            ->assertStatus(422);
    }

    public function test_a_quotation_or_proforma_cannot_be_marked_paid(): void
    {
        $quote = $this->issue('quotation')->json('data.id');
        $proforma = $this->issue('proforma')->json('data.id');
        $delivery = $this->issue('albaran')->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$quote}/payment", ['payment_status' => 'paid'])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$proforma}/payment", ['payment_status' => 'paid'])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$delivery}/payment", [
                'payment_status' => 'paid',
                'payment_method_id' => $this->cashId(),
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method.name', 'Cash');
    }

    public function test_a_quotation_can_be_edited_and_stock_stays_put(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 10,
            'article' => 'Quoted cable',
        ]);
        $id = $this->issue('quotation', $product, 2)->json('data.id');
        $number = 'Q-'.now()->format('Ym').'/00001';

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->payload('quotation', [[
                'product_id' => $product->id,
                'article' => 'Quoted cable',
                'quantity' => 3,
                'unit_price' => 800,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]], client: ['client_name' => 'Ana Ruiz']))
            ->assertOk()
            ->assertJsonPath('data.number', $number)
            ->assertJsonPath('data.client_name', 'Ana Ruiz')
            ->assertJsonPath('data.lines.0.quantity', 3)
            ->assertJsonPath('data.total_cents', 2904);

        $this->assertSame(10, $product->fresh()->quantity);
    }

    /** A team member made the way the owner makes one: through the team screen, from its ticks. */
    private function member(string $role, array $permissions, string $email = 'member@shop.test'): User
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [
            'name' => 'Marta Rivas',
            'email' => $email,
            'password' => 'secret-pass-1',
            'permissions' => $permissions,
        ])->assertCreated();

        return User::where('email', $email)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function deliveryLines(Product $product, int $quantity, string $article = 'Edited cable'): array
    {
        return $this->payload('albaran', [[
            'product_id' => $product->id,
            'article' => $article,
            'quantity' => $quantity,
            'unit_price' => 700,
            'discount_percent' => 0,
            'iva_percent' => 0,
        ]], client: ['client_name' => 'Ana Ruiz']);
    }

    public function test_an_unpaid_delivery_note_can_be_edited_and_only_the_difference_moves_stock(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 10, 'article' => 'Edited cable']);
        $created = $this->issue('albaran', $product, 3)->assertCreated();
        $id = $created->json('data.id');
        $number = $created->json('data.number');
        $issuedAt = SalesDocument::find($id)->issued_at->toIso8601String();
        $this->assertSame(7, $product->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/app/sales/{$id}")
            ->assertJsonPath('data.can_edit', true);

        // 3 pieces become 5: two more leave the shelf, not five.
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->deliveryLines($product, 5))
            ->assertOk()
            ->assertJsonPath('data.number', $number)
            ->assertJsonPath('data.client_name', 'Ana Ruiz')
            ->assertJsonPath('data.lines.0.quantity', 5)
            ->assertJsonPath('data.total_cents', 3500);
        $this->assertSame(5, $product->fresh()->quantity);
        $this->assertSame($issuedAt, SalesDocument::find($id)->issued_at->toIso8601String());

        // and down to 1: four come back.
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->deliveryLines($product, 1))
            ->assertOk();
        $this->assertSame(9, $product->fresh()->quantity);
    }

    public function test_a_delivery_note_edit_cannot_take_more_than_is_in_stock_and_changes_nothing(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 5]);
        $id = $this->issue('albaran', $product, 2)->json('data.id');
        $this->assertSame(3, $product->fresh()->quantity);

        // 2 held + 3 left = 5 at most
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->deliveryLines($product, 6))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');

        $this->assertSame(3, $product->fresh()->quantity);
        $this->assertSame(2, SalesDocument::find($id)->lines()->first()->quantity);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->deliveryLines($product, 5))
            ->assertOk();
        $this->assertSame(0, $product->fresh()->quantity);
    }

    public function test_a_paid_delivery_note_cannot_be_edited(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 10]);
        $id = $this->issue('albaran', $product, 2)->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}/payment", ['payment_status' => 'paid', 'payment_method_id' => $this->cashId()])
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/app/sales/{$id}")
            ->assertJsonPath('data.can_edit', false);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->deliveryLines($product, 4))
            ->assertStatus(422);

        $this->assertSame(8, $product->fresh()->quantity);
        $this->assertSame(2, SalesDocument::find($id)->lines()->first()->quantity);
    }

    public function test_a_delivery_note_with_an_invoice_cannot_be_edited(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 10]);
        $id = $this->issue('albaran', $product, 2)->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/invoice", ['payment_status' => 'pending'])
            ->assertSuccessful();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->deliveryLines($product, 4))
            ->assertStatus(422);
    }

    public function test_editing_a_delivery_note_needs_its_own_permission(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 10]);
        $id = $this->issue('albaran', $product, 2)->json('data.id');

        $viewer = $this->member('cashier', ['delivery_notes.view', 'delivery_notes.create', 'delivery_notes.pay']);
        $this->actingAs($viewer, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->deliveryLines($product, 3))
            ->assertForbidden();

        $editor = $this->member('cashier', ['delivery_notes.view', 'delivery_notes.update'], 'editor@shop.test');
        $this->actingAs($editor, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->deliveryLines($product, 3))
            ->assertOk();
        $this->assertSame(7, $product->fresh()->quantity);
    }

    public function test_an_invoice_cannot_be_edited(): void
    {
        $id = $this->issue('factura')->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->payload('factura'))
            ->assertStatus(422);
    }

    public function test_converting_a_quote_to_an_invoice_takes_stock_and_hides_the_quote(): void
    {
        $month = now()->format('Ym');
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 8]);
        $id = $this->issue('quotation', $product, 2)->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", ['type' => 'factura', 'payment_status' => 'pending'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'factura')
            ->assertJsonPath('data.number', "F-{$month}/00001")
            ->assertJsonPath('data.payment_status', 'pending');

        $this->assertSame(6, $product->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/sales?type=quotation')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", ['type' => 'albaran', 'payment_status' => 'pending'])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->payload('quotation'))
            ->assertStatus(422);
    }

    public function test_converting_a_quote_to_a_delivery_note_drops_iva_and_can_be_paid(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 5]);
        $id = $this->issue('quotation', $product, 2)->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", ['type' => 'albaran', 'payment_status' => 'paid'])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", [
                'type' => 'albaran',
                'payment_status' => 'paid',
                'payment_method_id' => $this->cashId(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'albaran')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.tax_cents', 0)
            ->assertJsonPath('data.lines.0.iva_percent', 0)
            ->assertJsonPath('data.payment_method.name', 'Cash');

        $this->assertSame(3, $product->fresh()->quantity);
    }

    public function test_settling_some_proforma_pieces_marks_it_partial_and_leaves_stock(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 10,
            'article' => 'Wholesale box',
        ]);

        $created = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('proforma', [[
                'product_id' => $product->id,
                'article' => 'Wholesale box',
                'quantity' => 3,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 0,
            ]]))
            ->assertCreated()
            ->json('data');

        $this->assertSame(7, $product->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.paid_this_month', 0);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$created['id']}/settle", [
                'payment_method_id' => $this->cashId(),
                'lines' => [[
                    'line_id' => $created['lines'][0]['id'],
                    'quantity' => 1,
                    'unit_price' => 800,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.is_partial', true)
            ->assertJsonPath('data.settled_cents', 800)
            ->assertJsonPath('data.lines.0.settled_quantity', 1)
            ->assertJsonPath('data.lines.0.remaining_quantity', 2)
            ->assertJsonPath('data.settlements.0.total_cents', 800)
            ->assertJsonPath('data.settlements.0.payment_method.name', 'Cash');

        $this->assertSame(7, $product->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.paid_this_month', 800);
    }

    public function test_settling_the_rest_of_a_proforma_marks_it_paid(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 10,
            'article' => 'Wholesale box',
        ]);

        $created = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('proforma', [[
                'product_id' => $product->id,
                'article' => 'Wholesale box',
                'quantity' => 3,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 0,
            ]]))
            ->assertCreated()
            ->json('data');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$created['id']}/settle", [
                'payment_method_id' => $this->cashId(),
                'lines' => [[
                    'line_id' => $created['lines'][0]['id'],
                    'quantity' => 1,
                    'unit_price' => 800,
                ]],
            ])
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$created['id']}/settle", [
                'payment_method_id' => $this->cashId(),
                'lines' => [[
                    'line_id' => $created['lines'][0]['id'],
                    'quantity' => 2,
                    'unit_price' => 500,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.is_partial', false)
            ->assertJsonPath('data.settled_cents', 1800)
            ->assertJsonPath('data.lines.0.remaining_quantity', 0)
            ->assertJsonCount(2, 'data.settlements');

        $this->assertSame(7, $product->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.paid_this_month', 1800);
    }

    public function test_a_proforma_settle_rejects_over_qty_missing_method_and_invoices(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 10]);
        $proforma = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('proforma', [[
                'product_id' => $product->id,
                'article' => $product->article,
                'quantity' => 3,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 0,
            ]]))
            ->json('data');
        $invoice = $this->issue('factura')->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$proforma['id']}/settle", [
                'payment_method_id' => $this->cashId(),
                'lines' => [[
                    'line_id' => $proforma['lines'][0]['id'],
                    'quantity' => 4,
                    'unit_price' => 800,
                ]],
            ])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$proforma['id']}/settle", [
                'lines' => [[
                    'line_id' => $proforma['lines'][0]['id'],
                    'quantity' => 1,
                    'unit_price' => 800,
                ]],
            ])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$invoice}/settle", [
                'payment_method_id' => $this->cashId(),
                'lines' => [[
                    'line_id' => 1,
                    'quantity' => 1,
                    'unit_price' => 800,
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_a_proforma_settlement_quantity_can_be_edited(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 10,
            'article' => 'Wholesale box',
        ]);

        $created = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('proforma', [[
                'product_id' => $product->id,
                'article' => 'Wholesale box',
                'quantity' => 3,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 0,
            ]]))
            ->assertCreated()
            ->json('data');

        $settled = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$created['id']}/settle", [
                'payment_method_id' => $this->cashId(),
                'lines' => [[
                    'line_id' => $created['lines'][0]['id'],
                    'quantity' => 1,
                    'unit_price' => 800,
                ]],
            ])
            ->assertOk()
            ->json('data');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$created['id']}/settlements/{$settled['settlements'][0]['id']}", [
                'lines' => [[
                    'line_id' => $created['lines'][0]['id'],
                    'quantity' => 2,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.settled_cents', 1600)
            ->assertJsonPath('data.lines.0.settled_quantity', 2)
            ->assertJsonPath('data.lines.0.remaining_quantity', 1)
            ->assertJsonPath('data.settlements.0.total_cents', 1600)
            ->assertJsonPath('data.settlements.0.lines.0.unit_price', 800);

        $this->assertSame(7, $product->fresh()->quantity);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$created['id']}/settlements/{$settled['settlements'][0]['id']}", [
                'lines' => [[
                    'line_id' => $created['lines'][0]['id'],
                    'quantity' => 3,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.settled_cents', 2400)
            ->assertJsonPath('data.lines.0.remaining_quantity', 0);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$created['id']}/settlements/{$settled['settlements'][0]['id']}", [
                'lines' => [[
                    'line_id' => $created['lines'][0]['id'],
                    'quantity' => 4,
                ]],
            ])
            ->assertStatus(422);

        $this->assertSame(7, $product->fresh()->quantity);
    }

    public function test_the_sales_list_searches_number_and_client_and_pages_eight(): void
    {
        $this->issue('factura', client: ['client_name' => 'Ruiz Tiles']);
        $this->issue('factura', client: ['client_name' => 'Counter sale', 'client_company' => 'Techo Sphere']);
        for ($i = 0; $i < 7; $i++) {
            $this->issue('factura');
        }

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/sales?type=factura&search=Ruiz')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client_name', 'Ruiz Tiles')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.counts.all', 1);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/sales?type=factura&search=Techo')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client_company', 'Techo Sphere');

        $month = now()->format('Ym');
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/app/sales?type=factura&search=F-{$month}/00001")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.number', "F-{$month}/00001");

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/sales?type=factura')
            ->assertOk()
            ->assertJsonCount(8, 'data.items')
            ->assertJsonPath('data.meta.per_page', 8)
            ->assertJsonPath('data.meta.last_page', 2)
            ->assertJsonPath('data.meta.total', 9)
            ->assertJsonPath('data.counts.all', 9);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/sales?type=factura&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.meta.current_page', 2);
    }

    public function test_the_sales_list_filters_voided_apart_from_paid(): void
    {
        $paid = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('factura', payment: [
                'payment_status' => 'paid',
                'payment_method_id' => $this->cashId(),
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->issue('factura');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$paid}/void", ['reason' => 'Client returned the order'])
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/sales?type=factura&display_status=voided')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $paid)
            ->assertJsonPath('data.counts.all', 2)
            ->assertJsonPath('data.counts.voided', 1)
            ->assertJsonPath('data.counts.pending', 1)
            ->assertJsonPath('data.counts.paid', 0);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/sales?type=factura&display_status=paid')
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.counts.paid', 0);
    }

    public function test_another_company_cannot_read_the_document(): void
    {
        $id = $this->issue('factura')->json('data.id');

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/app/sales/{$id}")
            ->assertNotFound();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/app/sales')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function issueOn(string $type, string $issuedAt)
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', array_merge($this->payload($type), ['issued_at' => $issuedAt]));
    }

    public function test_numbers_carry_the_month_and_a_five_digit_serial(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->issueOn('factura', '2026-10-15T10:00:00+00:00')->assertCreated()->assertJsonPath('data.number', 'F-202610/00001');
        $this->issueOn('factura', '2026-10-15T09:00:00+00:00')->assertCreated()->assertJsonPath('data.number', 'F-202610/00002');
        $this->issueOn('albaran', '2026-10-15T09:00:00+00:00')->assertCreated()->assertJsonPath('data.number', 'AL-202610/00001');
        $this->issueOn('proforma', '2026-10-15T09:00:00+00:00')->assertCreated()->assertJsonPath('data.number', 'PF-202610/00001');
    }

    public function test_the_serial_starts_again_in_a_new_month(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->issueOn('factura', '2026-10-15T10:00:00+00:00')->assertCreated();
        $this->issueOn('factura', '2026-10-16T10:00:00+00:00')->assertStatus(422);

        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->issueOn('factura', '2026-11-02T09:00:00+00:00')->assertCreated()->assertJsonPath('data.number', 'F-202611/00001');
    }

    public function test_a_past_month_takes_its_last_day_and_continues_that_months_series(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->issueOn('factura', '2026-09-30T12:00:00+00:00')->assertCreated()->assertJsonPath('data.number', 'F-202609/00001');
        $this->issueOn('factura', '2026-09-30T18:00:00+00:00')->assertCreated()->assertJsonPath('data.number', 'F-202609/00002');
        // and October carries on separately
        $this->issueOn('factura', '2026-10-15T10:00:00+00:00')->assertCreated()->assertJsonPath('data.number', 'F-202610/00001');
        $this->issueOn('factura', '2026-09-30T20:00:00+00:00')->assertCreated()->assertJsonPath('data.number', 'F-202609/00003');
    }

    public function test_a_past_month_refuses_any_day_but_its_last(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->issueOn('factura', '2026-09-15T12:00:00+00:00')
            ->assertStatus(422)
            ->assertJsonValidationErrors('issued_at');
        $this->issueOn('albaran', '2026-09-29T12:00:00+00:00')->assertStatus(422);
        $this->issueOn('proforma', '2026-08-30T12:00:00+00:00')->assertStatus(422);
    }

    public function test_last_days_follow_the_length_of_the_month(): void
    {
        // 2028 is a leap year, 2027 is not.
        Carbon::setTestNow('2028-03-10 10:00:00');
        $this->assertNull(IssueDate::problem(Carbon::parse('2028-02-29T12:00:00+00:00')));
        $this->assertNotNull(IssueDate::problem(Carbon::parse('2028-02-28T12:00:00+00:00')));

        Carbon::setTestNow('2027-03-10 10:00:00');
        $this->assertNull(IssueDate::problem(Carbon::parse('2027-02-28T12:00:00+00:00')));
        $this->assertNull(IssueDate::problem(Carbon::parse('2027-01-31T12:00:00+00:00')));
        $this->assertNotNull(IssueDate::problem(Carbon::parse('2027-04-30T12:00:00+00:00')));
        $this->assertSame(202702, IssueDate::period(Carbon::parse('2027-02-28T12:00:00+00:00')));
    }

    public function test_the_future_is_refused(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->issueOn('factura', '2026-10-16T10:00:00+00:00')->assertStatus(422)->assertJsonValidationErrors('issued_at');
        $this->issueOn('factura', '2026-11-30T10:00:00+00:00')->assertStatus(422);
    }

    public function test_the_month_is_read_from_the_date_as_the_user_wrote_it(): void
    {
        Carbon::setTestNow('2026-10-31 20:00:00');

        // 00:30 on 1 November in Karachi is still 31 October in UTC: the document belongs to November.
        Carbon::setTestNow('2026-10-31 19:45:00');
        $this->issueOn('factura', '2026-11-01T00:30:00+05:00')->assertCreated()->assertJsonPath('data.number', 'F-202611/00001');
    }

    public function test_a_quotation_may_keep_any_date(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->issueOn('quotation', '2026-09-24T12:00:00+00:00')->assertCreated()->assertJsonPath('data.number', 'Q-202609/00001');
    }

    public function test_the_preview_follows_the_month_of_the_date(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->issueOn('factura', '2026-09-30T12:00:00+00:00')->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/sales/preview?type=factura')
            ->assertOk()->assertJsonPath('data.number', 'F-202610/00001');
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/sales/preview?type=factura&issued_at=2026-09-30T12:00:00%2B00:00')
            ->assertOk()->assertJsonPath('data.number', 'F-202609/00002');
    }

    public function test_a_document_made_from_another_gets_the_current_months_number(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->issueOn('albaran', '2026-09-30T12:00:00+00:00')->assertCreated()->assertJsonPath('data.number', 'AL-202609/00001');

        Carbon::setTestNow('2026-11-03 10:00:00');
        $id = SalesDocument::query()->where('type', 'albaran')->value('id');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$id}/invoice", [
            'payment_status' => 'pending',
        ])->assertSuccessful();
        $this->assertSame('F-202611/00001', SalesDocument::query()->where('type', 'factura')->value('number'));
    }

    private function issue(string $type, ?Product $product = null, int $quantity = 1, array $client = [])
    {
        $product ??= Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 10]);

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', $this->payload($type, [[
            'product_id' => $product->id,
            'article' => $product->article,
            'quantity' => $quantity,
            'unit_price' => 1000,
            'discount_percent' => 0,
            'iva_percent' => 21,
        ]], $client));
    }

    private function cashId(): int
    {
        return (int) CompanyPaymentMethod::query()->where('name', 'Cash')->value('id');
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $lines
     * @param  array<string, mixed>  $client
     * @param  array<string, mixed>  $payment
     */
    private function payload(string $type, ?array $lines = null, array $client = [], array $payment = []): array
    {
        $lines ??= [[
            'article' => 'Walk-in item',
            'quantity' => 1,
            'unit_price' => 500,
            'discount_percent' => 0,
            'iva_percent' => 21,
        ]];

        return array_merge([
            'type' => $type,
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'save_customer' => false,
            'client_name' => 'Counter sale',
            'lines' => $lines,
        ], $client, $payment);
    }
}
