<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
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
        $year = now()->year;

        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$year}/0001");
        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$year}/0002");
        $this->issue('albaran')->assertCreated()->assertJsonPath('data.number', "AL-{$year}/0001");
        $this->issue('abono')->assertCreated()->assertJsonPath('data.number', "AB-{$year}/0001");
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

    public function test_an_abono_puts_the_stock_back(): void
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 5]);

        $this->issue('abono', $product, 2)->assertCreated();

        $this->assertSame(7, $product->fresh()->quantity);
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
            ->patchJson('/api/app/sales/'.$response->json('data.id').'/payment', ['payment_status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');
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
            ->assertJsonCount(0, 'data');
    }

    private function issue(string $type, ?Product $product = null, int $quantity = 1)
    {
        $product ??= Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 10]);

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', $this->payload($type, [[
            'product_id' => $product->id,
            'article' => $product->article,
            'quantity' => $quantity,
            'unit_price' => 1000,
            'discount_percent' => 0,
            'iva_percent' => 21,
        ]]));
    }

    private function payload(string $type, ?array $lines = null, array $client = []): array
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
        ], $client);
    }
}
