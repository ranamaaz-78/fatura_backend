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

class AlbaranInvoiceTest extends TestCase
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

    private function albaran(string $payment = 'pending')
    {
        $body = [
            'type' => 'albaran',
            'issued_at' => now()->toIso8601String(),
            'payment_status' => $payment,
            'save_customer' => false,
            'client_name' => 'Taller Rivas',
            'client_nif' => 'B12345678',
            'lines' => [[
                'product_id' => $this->product->id,
                'article' => $this->product->article,
                'quantity' => 3,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ];

        if ($payment === 'paid') {
            $body['payment_method_id'] = (int) CompanyPaymentMethod::query()->where('name', 'Cash')->value('id');
        }

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', $body)->assertCreated();
    }

    private function invoice(int $albaranId, array $body = [])
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$albaranId}/invoice", $body);
    }

    public function test_the_dashboard_and_reports_count_the_iva_added_to_a_paid_delivery_note(): void
    {
        $this->product->update(['quantity' => 50]);
        $cash = (int) CompanyPaymentMethod::query()->where('name', 'Cash')->value('id');
        $as = fn () => $this->actingAs($this->owner, 'sanctum');

        // Two delivery notes paid at 30.00 each. One invoice (21 % IVA) pays the 6.30 on top now; the other leaves it owed.
        $a = $this->albaran('paid');
        $this->invoice($a->json('data.id'), ['iva' => [$a->json('data.lines.0.id') => 21], 'pay_difference' => true, 'payment_method_id' => $cash])->assertCreated();
        $b = $this->albaran('paid');
        $this->invoice($b->json('data.id'), ['iva' => [$b->json('data.lines.0.id') => 21]])->assertCreated();

        // Dashboard: 30 + 30 paid on the notes, 6.30 paid on the first invoice; 6.30 still owed on the second.
        $kpis = $as()->getJson('/api/app/dashboard')->assertOk()->json('data.kpis');
        $this->assertSame(6630, $kpis['paid_this_month']);
        $this->assertSame(630, $kpis['outstanding']);
        $this->assertSame(4, $kpis['document_count']);

        // Sales report: the notes at their price, the invoices at what they added.
        $sales = $as()->getJson('/api/app/reports/sales')->assertOk()->json('data.kpis');
        $this->assertSame(7260, $sales['total_cents']);
        $this->assertSame(630, $sales['outstanding_cents']);

        // Tax report: the IVA of the two invoices (the base was counted on the notes).
        $tax = $as()->getJson('/api/app/reports/tax')->assertOk()->json('data.kpis');
        $this->assertSame(1260, $tax['tax_cents']);
        $this->assertSame(6000, $tax['base_cents']);

        // Pieces sold are not counted twice.
        $sold = collect($as()->getJson('/api/app/reports/sold')->assertOk()->json('data.rows'));
        $this->assertSame(6, (int) $sold->sum('quantity'));

        // A delivery note that is still unpaid, invoiced: only the note counts (its invoice is paper).
        $c = $this->albaran('pending');
        $this->invoice($c->json('data.id'), ['iva' => [$c->json('data.lines.0.id') => 21]])->assertCreated();
        $this->assertSame(5, $as()->getJson('/api/app/dashboard')->json('data.kpis.document_count'));
    }

    public function test_a_paid_delivery_notes_unpaid_iva_is_paid_now_or_left_pending(): void
    {
        $this->product->update(['quantity' => 50]);
        $cash = (int) CompanyPaymentMethod::query()->where('name', 'Cash')->value('id');
        $card =(int) CompanyPaymentMethod::query()->where('name', '!=', 'Cash')->where('is_active', true)->value('id') ?: $cash;

        // 3 x 10.00 = 30.00 paid on the delivery note. With 21 % IVA the invoice is 36.30: 6.30 is still owed.
        $a = $this->albaran('paid');
        $line = $a->json('data.lines.0.id');
        $paidNow = $this->invoice($a->json('data.id'), ['iva' => [$line => 21], 'pay_difference' => true, 'payment_method_id' => $card])
            ->assertCreated()
            ->assertJsonPath('data.total_cents', 3630)
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method.id', $card);

        // Left unpaid: the invoice stays pending until it is paid.
        $b = $this->albaran('paid');
        $leftOpen = $this->invoice($b->json('data.id'), ['iva' => [$b->json('data.lines.0.id') => 21]])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.payment_method_id', null);

        // Paying the difference needs a method.
        $c = $this->albaran('paid');
        $this->invoice($c->json('data.id'), ['iva' => [$c->json('data.lines.0.id') => 21], 'pay_difference' => true])->assertStatus(422);

        // The Payments page counts the difference as its own row: received for the first, owed for the second.
        $rows = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/app/payments')->assertOk()->json('data.items'))
            ->where('kind', 'difference')->keyBy('document_id');
        $this->assertSame(630, $rows[$paidNow->json('data.id')]['amount_cents']);
        $this->assertSame('received', $rows[$paidNow->json('data.id')]['status']);
        $this->assertSame(630, $rows[$leftOpen->json('data.id')]['outstanding_cents']);
        $this->assertSame('pending', $rows[$leftOpen->json('data.id')]['status']);

        // A delivery note that was not paid gives a pending invoice and no difference row.
        $d = $this->albaran('pending');
        $this->invoice($d->json('data.id'), ['iva' => [$d->json('data.lines.0.id') => 21]])->assertCreated()->assertJsonPath('data.payment_status', 'pending');
        $this->assertCount(2, collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/app/payments')->json('data.items'))->where('kind', 'difference'));

        // No IVA, no recargo: nothing is left to pay, so the invoice is simply paid.
        $e = $this->albaran('paid');
        $this->invoice($e->json('data.id'), ['iva' => [$e->json('data.lines.0.id') => 0]])->assertCreated()->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_the_invoice_fills_only_the_client_details_the_delivery_note_lacks(): void
    {
        $albaran = $this->albaran();

        $response = $this->invoice($albaran->json('data.id'), [
            // Name is not even accepted here; the NIF the note has stays; the blanks are filled.
            'client_name' => 'Someone Else',
            'client_nif' => 'X0000000X',
            'client_address' => 'Calle Mayor 1, Madrid',
            'client_phone' => '+34 600 111 222',
            'client_email' => 'Taller@Rivas.test',
            'client_company' => 'Rivas S.L.',
        ])->assertCreated();

        $response->assertJsonPath('data.client_name', 'Taller Rivas')
            ->assertJsonPath('data.client_nif', 'B12345678')
            ->assertJsonPath('data.client_address', 'Calle Mayor 1, Madrid')
            ->assertJsonPath('data.client_phone', '+34 600 111 222')
            ->assertJsonPath('data.client_email', 'taller@rivas.test')
            ->assertJsonPath('data.client_company', 'Rivas S.L.');

        // The delivery note itself is untouched.
        $this->assertNull(SalesDocument::query()->findOrFail($albaran->json('data.id'))->client_address);
    }

    public function test_a_filled_detail_is_never_replaced_and_a_saved_client_gets_the_blanks_filled(): void
    {
        $body = [
            'type' => 'albaran', 'issued_at' => now()->toIso8601String(), 'payment_status' => 'pending',
            'save_customer' => true, 'client_name' => 'Taller Rivas', 'client_phone' => '+34 611 000 111',
            'lines' => [[
                'product_id' => $this->product->id, 'article' => $this->product->article, 'quantity' => 1,
                'unit_price' => 1000, 'discount_percent' => 0, 'iva_percent' => 21,
            ]],
        ];
        $albaran = $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', $body)->assertCreated();

        $invoice = $this->invoice($albaran->json('data.id'), ['client_phone' => '+34 999 999 999', 'client_address' => 'Plaza 5'])
            ->assertCreated()
            ->assertJsonPath('data.client_phone', '+34 611 000 111')
            ->assertJsonPath('data.client_address', 'Plaza 5');

        // The saved client now has the address too, and keeps its own phone.
        $customer = \App\Models\Customer::query()->findOrFail($invoice->json('data.customer_id'));
        $this->assertSame('Plaza 5', $customer->address);
        $this->assertSame('+34 611 000 111', $customer->phone);
    }

    public function test_a_delivery_note_becomes_an_invoice_with_iva_and_recargo_and_moves_no_stock(): void
    {
        $albaran = $this->albaran();
        $this->assertSame(7, $this->product->fresh()->quantity);
        // A delivery note is issued without IVA.
        $this->assertSame(0, (int) $albaran->json('data.tax_cents'));

        $lineId = $albaran->json('data.lines.0.id');
        $rate = RecargoRate::query()->where('rate', 5.2)->value('id');

        $response = $this->invoice($albaran->json('data.id'), ['iva' => [$lineId => 21], 'recargo_rate_id' => $rate])
            ->assertCreated()
            ->assertJsonPath('data.type', 'factura')
            ->assertJsonPath('data.from_document_id', $albaran->json('data.id'))
            ->assertJsonPath('data.client_name', 'Taller Rivas')
            ->assertJsonPath('data.base_cents', 3000)
            ->assertJsonPath('data.tax_cents', 630)
            ->assertJsonPath('data.recargo_cents', 156)
            ->assertJsonPath('data.total_cents', 3786)
            ->assertJsonPath('data.payment_status', 'pending');

        $this->assertNotNull($response->json('data.verify_code'));
        // The delivery note already took the stock out.
        $this->assertSame(7, $this->product->fresh()->quantity);

        $note = SalesDocument::findOrFail($albaran->json('data.id'));
        $this->assertSame($response->json('data.id'), $note->converted_to_id);
        $this->assertFalse($note->canInvoice());
    }

    public function test_it_can_only_be_invoiced_once_until_that_invoice_is_voided(): void
    {
        $albaran = $this->albaran('paid');
        $id = $albaran->json('data.id');

        // A paid delivery note pays its own price; the invoice adds IVA on top, so it is paid here by paying that.
        $cash = (int) CompanyPaymentMethod::query()->where('name', 'Cash')->value('id');
        $first = $this->invoice($id, ['pay_difference' => true, 'payment_method_id' => $cash, 'iva' => [$albaran->json('data.lines.0.id') => 21]])
            ->assertCreated()->assertJsonPath('data.payment_status', 'paid');
        $this->invoice($id)->assertStatus(422);

        // The delivery note stands under its invoice: it cannot be voided first.
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/sales/{$id}/void", ['reason' => 'Mistake'])->assertStatus(422);

        // Voiding the invoice puts nothing back in stock (it never took any) and frees the delivery note.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales/'.$first->json('data.id').'/void', ['reason' => 'Wrong IVA'])->assertOk();
        $this->assertSame(7, $this->product->fresh()->quantity);

        $this->invoice($id)->assertCreated();
    }

    public function test_only_a_delivery_note_can_be_turned_into_an_invoice_this_way(): void
    {
        $invoice = $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', [
            'type' => 'factura',
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'save_customer' => false,
            'client_name' => 'Counter sale',
            'lines' => [['article' => 'Walk-in item', 'quantity' => 1, 'unit_price' => 500, 'discount_percent' => 0, 'iva_percent' => 21]],
        ])->assertCreated();

        $this->invoice($invoice->json('data.id'))->assertStatus(422);
    }

    public function test_the_invoice_made_from_a_delivery_note_is_not_counted_as_a_second_sale(): void
    {
        $this->albaran('paid');
        $money = fn () => collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/app/dashboard')->json('data.kpis'))
            ->only(['paid_this_month', 'outstanding', 'document_count', 'paid_count'])
            ->all();
        $before = $money();
        $this->assertSame(3000, $before['paid_this_month']);

        $note = SalesDocument::query()->where('type', 'albaran')->firstOrFail();
        // With no IVA and no recargo the invoice adds nothing to what was paid.
        $this->invoice($note->id, ['iva' => [$note->lines()->value('id') => 0]])->assertCreated();

        // The delivery note already counted as the sale; its invoice is paper only.
        $this->assertSame($before, $money());
    }
}
