<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
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

    public function test_sales_excludes_quotes_and_voided_documents(): void
    {
        $this->issuePaid('factura', 'Adeel');
        $this->issuePending('factura', 'Walk-in');
        $this->issue('quotation', 'Quote client');

        $voided = $this->issuePaid('factura', 'Returned');
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales/'.$voided.'/void', ['reason' => 'Client returned the order'])
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/reports/sales')
            ->assertOk()
            ->assertJsonPath('data.kind', 'sales')
            ->assertJsonPath('data.kpis.document_count', 2)
            ->assertJsonPath('data.kpis.total_cents', 1210 * 2)
            ->assertJsonPath('data.kpis.paid_cents', 1210)
            ->assertJsonPath('data.kpis.outstanding_cents', 1210);
    }

    public function test_sales_respects_a_custom_date_range(): void
    {
        $this->issuePaid('factura', 'Today', ['issued_at' => now()->toIso8601String()]);
        $this->issuePaid('factura', 'Last month', ['issued_at' => now()->subMonth()->toIso8601String()]);

        $from = now()->subMonth()->toDateString();
        $to = now()->subMonth()->toDateString();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/app/reports/sales?period=custom&from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('data.kpis.document_count', 1)
            ->assertJsonPath('data.kpis.paid_cents', 1210);
    }

    public function test_tax_is_grouped_by_rate(): void
    {
        $this->issuePaid('factura', 'Adeel');
        $this->issuePaid('albaran', 'Goppal');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/reports/tax')
            ->assertOk()
            ->assertJsonPath('data.kpis.tax_cents', 210)
            ->assertJsonPath('data.kpis.base_cents', 2000)
            ->assertJsonCount(2, 'data.rows');
    }

    public function test_best_sellers_sum_quantities_from_sale_lines(): void
    {
        $shirt = Product::factory()->create([
            'company_id' => $this->company->id,
            'article' => 'Cotton shirt',
            'quantity' => 20,
        ]);
        $this->issuePaid('factura', 'Adeel', [
            'lines' => [[
                'product_id' => $shirt->id,
                'article' => 'Cotton shirt',
                'quantity' => 3,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/reports/products')
            ->assertOk()
            ->assertJsonPath('data.kpis.product_count', 1)
            ->assertJsonPath('data.kpis.quantity', 3)
            ->assertJsonPath('data.rows.0.article', 'Cotton shirt')
            ->assertJsonPath('data.rows.0.quantity', 3);
    }

    public function test_outstanding_lists_open_documents(): void
    {
        $this->issuePaid('factura', 'Adeel');
        $this->issuePending('factura', 'Walk-in');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/reports/outstanding')
            ->assertOk()
            ->assertJsonPath('data.kpis.document_count', 1)
            ->assertJsonPath('data.kpis.outstanding_cents', 1210)
            ->assertJsonPath('data.rows.0.client_name', 'Walk-In');
    }

    public function test_payments_group_received_cents_by_method(): void
    {
        $this->issuePaid('factura', 'Adeel');
        $this->issuePaid('factura', 'Goppal', ['payment_method_id' => $this->cardId()]);
        $this->issuePending('factura', 'Walk-in');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/reports/payments')
            ->assertOk()
            ->assertJsonPath('data.kpis.document_count', 3)
            ->assertJsonPath('data.kpis.received_cents', 2420);

        $names = collect($this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/reports/payments')
            ->json('data.rows'))
            ->pluck('method_name')
            ->all();

        $this->assertContains('Cash', $names);
        $this->assertContains('Card', $names);
        $this->assertContains(null, $names);
    }

    public function test_stock_value_uses_buying_price_cents(): void
    {
        Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 4,
            'buying_price' => 250,
            'minimum_stock' => 1,
        ]);
        Product::factory()->lowStock()->create([
            'company_id' => $this->company->id,
            'article' => 'Low lamp',
            'buying_price' => 100,
        ]);
        Product::factory()->outOfStock()->create([
            'company_id' => $this->company->id,
            'buying_price' => 999,
        ]);

        $payload = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/reports/stock')
            ->assertOk()
            ->assertJsonPath('data.kpis.product_count', 3)
            ->assertJsonPath('data.kpis.ok_count', 1)
            ->assertJsonPath('data.kpis.low_count', 1)
            ->assertJsonPath('data.kpis.out_count', 1)
            ->assertJsonPath('data.kpis.stock_value_cents', 4 * 250 + 2 * 100)
            ->json('data.rows');

        $low = collect($payload)->firstWhere('article', 'Low lamp');
        $this->assertNotNull($low);
        $this->assertSame('low', $low['band']);
    }

    public function test_clients_rank_sales_in_the_period(): void
    {
        $this->issuePaid('factura', 'Adeel');
        $this->issuePaid('factura', 'Adeel');
        $this->issuePending('factura', 'Walk-in');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/reports/clients')
            ->assertOk()
            ->assertJsonPath('data.kpis.client_count', 2)
            ->assertJsonPath('data.rows.0.client_name', 'Adeel')
            ->assertJsonPath('data.rows.0.document_count', 2)
            ->assertJsonPath('data.rows.0.total_cents', 2420)
            ->assertJsonPath('data.rows.1.client_name', 'Walk-In')
            ->assertJsonPath('data.rows.1.outstanding_cents', 1210);
    }

    public function test_suppliers_sum_catalog_stock_value(): void
    {
        $acme = Supplier::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Acme Parts',
        ]);
        Product::factory()->create([
            'company_id' => $this->company->id,
            'supplier_id' => $acme->id,
            'quantity' => 3,
            'buying_price' => 400,
        ]);
        Product::factory()->create([
            'company_id' => $this->company->id,
            'supplier_id' => null,
            'quantity' => 2,
            'buying_price' => 100,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/reports/suppliers')
            ->assertOk()
            ->assertJsonPath('data.kpis.supplier_count', 1)
            ->assertJsonPath('data.kpis.stock_value_cents', 1400)
            ->assertJsonPath('data.rows.0.supplier_name', 'Acme Parts')
            ->assertJsonPath('data.rows.0.stock_value_cents', 1200);
    }

    public function test_another_company_cannot_see_these_reports(): void
    {
        $this->issuePaid('factura', 'Adeel');

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/app/reports/sales')
            ->assertOk()
            ->assertJsonPath('data.kpis.document_count', 0)
            ->assertJsonPath('data.kpis.total_cents', 0);
    }

    public function test_unknown_kind_is_not_found(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/reports/purchases')
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function issuePaid(string $type, string $clientName, array $extra = []): int
    {
        return $this->issue($type, $clientName, array_merge([
            'payment_status' => 'paid',
            'payment_method_id' => $this->cashId(),
        ], $extra));
    }

    private function issuePending(string $type, string $clientName): int
    {
        return $this->issue($type, $clientName);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function issue(string $type, string $clientName, array $extra = []): int
    {
        $lines = $extra['lines'] ?? [[
            'article' => 'Walk-in item',
            'quantity' => 1,
            'unit_price' => 1000,
            'discount_percent' => 0,
            'iva_percent' => 21,
        ]];
        unset($extra['lines']);

        return (int) $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', array_merge([
                'type' => $type,
                'issued_at' => now()->toIso8601String(),
                'payment_status' => 'pending',
                'save_customer' => false,
                'client_name' => $clientName,
                'lines' => $lines,
            ], $extra))
            ->assertCreated()
            ->json('data.id');
    }

    private function cashId(): int
    {
        return (int) CompanyPaymentMethod::query()->where('name', 'Cash')->value('id');
    }

    private function cardId(): int
    {
        return (int) CompanyPaymentMethod::query()->where('name', 'Card')->value('id');
    }
}
