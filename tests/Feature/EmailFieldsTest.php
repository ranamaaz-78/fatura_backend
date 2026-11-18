<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailFieldsTest extends TestCase
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

    private function sale(array $extra = [])
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 10]);

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', array_merge([
            'type' => 'factura',
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'save_customer' => false,
            'client_name' => 'Counter sale',
            'lines' => [['product_id' => $product->id, 'article' => $product->article, 'quantity' => 1, 'unit_price' => 1000, 'discount_percent' => 0, 'iva_percent' => 21]],
        ], $extra));
    }

    public function test_a_client_keeps_an_email_in_small_letters_and_it_can_be_found(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $id = $this->postJson('/api/app/customers', ['name' => 'Ana Ruiz', 'email' => ' Ana.Ruiz@Example.COM '])->assertCreated()->assertJsonPath('data.email', 'ana.ruiz@example.com')->json('data.id');

        $this->getJson('/api/app/customers?search=example.com')->assertJsonCount(1, 'data');
        $this->patchJson("/api/app/customers/{$id}", ['name' => 'Ana Ruiz', 'email' => ''])->assertOk()->assertJsonPath('data.email', null);
        $this->patchJson("/api/app/customers/{$id}", ['name' => 'Ana Ruiz', 'email' => 'not an email'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_supplier_keeps_an_email_too(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $id = $this->postJson('/api/app/suppliers', ['name' => 'Luz del Sur', 'email' => 'Ventas@LuzDelSur.es'])->assertCreated()->assertJsonPath('data.email', 'ventas@luzdelsur.es')->json('data.id');

        $this->getJson('/api/app/suppliers?search=luzdelsur')->assertJsonCount(1, 'data');
        $this->patchJson("/api/app/suppliers/{$id}", ['name' => 'Luz del Sur', 'email' => 'bad@'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_sale_screen_sends_the_email_and_the_document_and_the_saved_client_keep_it(): void
    {
        $this->sale(['client_email' => 'Marta@Shop.es', 'save_customer' => true])
            ->assertCreated()
            ->assertJsonPath('data.client_email', 'marta@shop.es');

        $this->assertSame('marta@shop.es', Customer::withoutGlobalScopes()->firstOrFail()->email);

        // An existing client picked on the sale screen: the email typed there is kept on them too.
        $customer = Customer::withoutGlobalScopes()->firstOrFail();
        $this->sale(['customer_id' => $customer->id, 'client_email' => 'new@shop.es'])->assertCreated()->assertJsonPath('data.client_email', 'new@shop.es');
        $this->assertSame('new@shop.es', $customer->fresh()->email);

        $this->sale(['client_email' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors('client_email');
    }

    public function test_a_converted_document_keeps_the_email(): void
    {
        $quote = $this->sale(['type' => 'quotation', 'client_email' => 'marta@shop.es'])->assertCreated();

        $this->assertSame('marta@shop.es', $quote->json('data.client_email'));
    }
}
