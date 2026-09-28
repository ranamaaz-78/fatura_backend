<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    public function test_quotes_are_not_sales_but_show_in_recent(): void
    {
        $this->issuePaid('factura', 'Adeel');
        $this->issuePending('factura', 'Walk-in');
        $this->issue('quotation', 'Quote client');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.document_count', 2)
            ->assertJsonPath('data.kpis.open_count', 1)
            ->assertJsonPath('data.kpis.outstanding', 1210)
            ->assertJsonPath('data.kpis.paid_this_month', 1210)
            ->assertJsonPath('data.kpis.invoices_this_month', 2)
            ->assertJsonCount(3, 'data.recent');
    }

    public function test_low_stock_products_are_listed(): void
    {
        Product::factory()->outOfStock()->create([
            'company_id' => $this->company->id,
            'article' => 'USB cable',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.out_count', 1)
            ->assertJsonPath('data.low_stock.0.article', 'USB cable')
            ->assertJsonPath('data.low_stock.0.band', 'out');
    }

    public function test_another_company_cannot_see_these_kpis(): void
    {
        $this->issuePaid('factura', 'Adeel');

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.document_count', 0)
            ->assertJsonPath('data.kpis.paid_this_month', 0)
            ->assertJsonPath('data.kpis.outstanding', 0)
            ->assertJsonPath('data.kpis.tax_cents', 0)
            ->assertJsonPath('data.kpis.profit_cents', 0);
    }

    public function test_profit_is_selling_base_minus_buying_cost_and_quotes_add_no_tax(): void
    {
        $shirt = Product::factory()->create([
            'company_id' => $this->company->id,
            'article' => 'Cotton shirt',
            'quantity' => 20,
            'buying_price' => 400,
            'selling_price' => 1000,
        ]);
        $this->issuePaid('factura', 'Adeel', [
            'lines' => [[
                'product_id' => $shirt->id,
                'article' => 'Cotton shirt',
                'quantity' => 2,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ]);
        $this->issue('quotation', 'Quote client', [
            'lines' => [[
                'product_id' => $shirt->id,
                'article' => 'Cotton shirt',
                'quantity' => 9,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/dashboard')
            ->assertOk()
            ->assertJsonPath('data.kpis.base_cents', 2000)
            ->assertJsonPath('data.kpis.tax_cents', 420)
            ->assertJsonPath('data.kpis.cost_cents', 800)
            ->assertJsonPath('data.kpis.profit_cents', 1200);
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
}
