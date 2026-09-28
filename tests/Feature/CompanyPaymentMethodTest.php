<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $company->id]);
    }

    public function test_a_new_company_starts_with_cash_card_and_bank_transfer(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/payment-methods')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Cash')
            ->assertJsonPath('data.1.name', 'Card')
            ->assertJsonPath('data.2.name', 'Bank transfer');
    }

    public function test_a_method_can_be_added_renamed_toggled_and_removed(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/payment-methods', ['name' => 'EasyPaisa'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'EasyPaisa')
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/payment-methods/{$id}", ['name' => 'JazzCash'])
            ->assertOk()
            ->assertJsonPath('data.name', 'JazzCash');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/payment-methods/{$id}/active", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/payment-methods?status=active')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/payment-methods/{$id}")
            ->assertOk();

        $this->assertNull(CompanyPaymentMethod::withoutGlobalScopes()->find($id));
    }

    public function test_a_method_used_on_a_document_cannot_be_deleted(): void
    {
        $method = CompanyPaymentMethod::query()->where('name', 'Cash')->first();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', [
                'type' => 'factura',
                'issued_at' => now()->toIso8601String(),
                'payment_status' => 'paid',
                'payment_method_id' => $method->id,
                'save_customer' => false,
                'client_name' => 'Counter',
                'lines' => [[
                    'article' => 'Item',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'discount_percent' => 0,
                    'iva_percent' => 0,
                ]],
            ])
            ->assertCreated();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/payment-methods/{$method->id}")
            ->assertStatus(422);

        $this->assertNotNull(CompanyPaymentMethod::withoutGlobalScopes()->find($method->id));
    }

    public function test_another_company_cannot_change_these_methods(): void
    {
        $id = CompanyPaymentMethod::query()->where('name', 'Cash')->value('id');
        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/app/payment-methods/{$id}")
            ->assertNotFound();
    }
}
