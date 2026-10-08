<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentLedgerTest extends TestCase
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

    public function test_the_ledger_badges_received_pending_and_partial_documents(): void
    {
        $this->issuePaid('factura', 'Adeel');
        $this->issuePending('factura', 'Walk-in');
        $this->settleProforma('Goppal', 1, 800);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/payments')
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.stats.received_count', 2)
            ->assertJsonPath('data.stats.received_cents', 1210 + 800)
            ->assertJsonPath('data.stats.pending_count', 2)
            ->assertJsonPath('data.stats.outstanding_cents', 1210 + 2200)
            ->assertJsonPath('data.items.0.client_name', 'Goppal')
            ->assertJsonPath('data.items.0.status', 'partial')
            ->assertJsonPath('data.items.0.payment_method.name', 'Cash')
            ->assertJsonPath('data.items.0.outstanding_cents', 2200)
            ->assertJsonPath('data.items.1.client_name', 'Walk-In')
            ->assertJsonPath('data.items.1.status', 'pending')
            ->assertJsonPath('data.items.2.client_name', 'Adeel')
            ->assertJsonPath('data.items.2.status', 'received')
            ->assertJsonPath('data.items.2.payment_method.name', 'Cash');
    }

    public function test_the_ledger_can_be_searched_by_client(): void
    {
        $this->issuePaid('factura', 'Adeel');
        $this->issuePaid('albaran', 'Goppal');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/payments?search=Adeel')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client_name', 'Adeel')
            ->assertJsonPath('data.stats.received_count', 2);
    }

    public function test_the_ledger_can_be_filtered_by_day_week_and_custom_range(): void
    {
        $this->issuePaid('factura', 'Today', ['issued_at' => now()->toIso8601String()]);
        $this->issuePaid('factura', 'Last month', ['issued_at' => now()->subMonth()->toIso8601String()]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/payments?period=day')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client_name', 'Today');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/payments?period=month')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $from = now()->subMonth()->toDateString();
        $to = now()->subMonth()->toDateString();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/app/payments?period=custom&from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client_name', 'Last Month');
    }

    public function test_status_and_method_filters_apply_together(): void
    {
        $this->issuePaid('factura', 'Adeel');
        $this->issuePending('factura', 'Walk-in');
        $this->issuePaid('albaran', 'Goppal', ['payment_method_id' => $this->cardId()]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/payments?status=received&payment_method_id='.$this->cashId())
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client_name', 'Adeel')
            ->assertJsonPath('data.stats.received_count', 1);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/payments?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client_name', 'Walk-In')
            ->assertJsonPath('data.items.0.status', 'pending');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/payments?payment_method_id=none&status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.client_name', 'Walk-In');
    }

    public function test_another_company_cannot_see_these_payments(): void
    {
        $this->issuePaid('factura', 'Adeel');

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/app/payments')
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.stats.received_count', 0);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function issuePaid(string $type, string $clientName, array $extra = []): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload($type, $clientName, array_merge([
                'payment_status' => 'paid',
                'payment_method_id' => $this->cashId(),
            ], $extra)))
            ->assertCreated();
    }

    private function issuePending(string $type, string $clientName): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload($type, $clientName))
            ->assertCreated();
    }

    private function settleProforma(string $clientName, int $quantity, int $unitPrice): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'quantity' => 10,
        ]);

        $created = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', [
                'type' => 'proforma',
                'issued_at' => now()->toIso8601String(),
                'payment_status' => 'pending',
                'save_customer' => false,
                'client_name' => $clientName,
                'lines' => [[
                    'product_id' => $product->id,
                    'article' => $product->article,
                    'quantity' => 3,
                    'unit_price' => 1000,
                    'discount_percent' => 0,
                    'iva_percent' => 0,
                ]],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$created['id']}/settle", [
                'payment_method_id' => $this->cashId(),
                'lines' => [[
                    'line_id' => $created['lines'][0]['id'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ]],
            ])
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(string $type, string $clientName, array $extra = []): array
    {
        return array_merge([
            'type' => $type,
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'save_customer' => false,
            'client_name' => $clientName,
            'lines' => [[
                'article' => 'Walk-in item',
                'quantity' => 1,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ], $extra);
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
