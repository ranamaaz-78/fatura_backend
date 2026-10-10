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

        $first = $this->invoice($id)->assertCreated()->assertJsonPath('data.payment_status', 'paid');
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

        $id = SalesDocument::query()->where('type', 'albaran')->value('id');
        $this->invoice($id)->assertCreated();

        // The delivery note already counted as the sale; its invoice is paper only.
        $this->assertSame($before, $money());
    }
}
