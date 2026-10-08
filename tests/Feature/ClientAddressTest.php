<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ClientAddressTest extends TestCase
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

    /** @param  array<string, mixed>  $extra */
    private function issue(string $type, array $extra = []): TestResponse
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 50]);

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', array_merge([
            'type' => $type,
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'client_name' => 'Ada Client',
            'lines' => [[
                'product_id' => $product->id,
                'article' => $product->article,
                'quantity' => 1,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ], $extra));
    }

    public function test_the_address_is_kept_on_the_document_and_on_a_new_client(): void
    {
        $this->issue('factura', ['client_address' => 'Calle Mayor 5, Madrid', 'save_customer' => true])
            ->assertCreated()
            ->assertJsonPath('data.client_address', 'Calle Mayor 5, Madrid');

        $this->assertSame('Calle Mayor 5, Madrid', Customer::sole()->address);
    }

    public function test_a_saved_client_gets_the_address_typed_on_the_next_document(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'address' => 'Old street 1']);

        $this->issue('factura', ['customer_id' => $customer->id, 'client_address' => 'New street 9'])
            ->assertCreated()
            ->assertJsonPath('data.client_address', 'New Street 9');

        $this->assertSame('New Street 9', $customer->fresh()->address);
    }

    public function test_a_quotation_hands_its_address_to_the_invoice_made_from_it(): void
    {
        $id = $this->issue('quotation', ['client_address' => 'Plaza 3, Valencia'])->assertCreated()->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", ['type' => 'factura', 'payment_status' => 'pending'])
            ->assertCreated()
            ->assertJsonPath('data.client_address', 'Plaza 3, Valencia');
    }

    public function test_the_clients_screen_saves_and_returns_the_address(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers', ['name' => 'Grace', 'address' => 'Avenida 12'])
            ->assertCreated()
            ->assertJsonPath('data.address', 'Avenida 12');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers', ['name' => 'Too long', 'address' => str_repeat('x', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['address']);
    }
}
