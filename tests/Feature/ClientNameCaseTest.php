<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientNameCaseTest extends TestCase
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

    private function issue(array $client): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', array_merge([
                'type' => 'factura',
                'issued_at' => now()->toIso8601String(),
                'payment_status' => 'pending',
                'save_customer' => false,
                'lines' => [['article' => 'taladro inalámbrico', 'quantity' => 1, 'unit_price' => 500, 'discount_percent' => 0, 'iva_percent' => 21]],
            ], $client))
            ->assertCreated()
            ->json('data');
    }

    public function test_the_client_is_stored_and_printed_in_proper_case(): void
    {
        $document = $this->issue([
            'client_name' => 'rana MAAZ',
            'client_company' => 'taller de marta s.l.',
            'client_address' => 'calle mayor 5b, madrid',
        ]);

        $this->assertSame('Rana Maaz', $document['client_name']);
        $this->assertSame('Taller de Marta S.L.', $document['client_company']);
        $this->assertSame('Calle Mayor 5B, Madrid', $document['client_address']);

        $stored = SalesDocument::find($document['id']);
        $this->assertSame('Rana Maaz', $stored->client_name);
        $this->assertSame('Taller de Marta S.L.', $stored->client_company);
    }

    public function test_the_items_keep_the_wording_they_were_typed_with(): void
    {
        $document = $this->issue(['client_name' => 'ana mora']);

        $this->assertSame('taladro inalámbrico', $document['lines'][0]['article']);
    }

    public function test_a_document_issued_before_this_is_shown_in_proper_case_too(): void
    {
        $document = $this->issue(['client_name' => 'Ana Mora']);

        // An older row, saved lower case before names were tidied.
        SalesDocument::query()->whereKey($document['id'])->toBase()->update(['client_name' => 'luis garcía', 'client_company' => 'ferretería alameda s.l.']);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/app/sales/{$document['id']}")
            ->assertOk()
            ->assertJsonPath('data.client_name', 'Luis García')
            ->assertJsonPath('data.client_company', 'Ferretería Alameda S.L.');
    }

    public function test_a_saved_client_is_stored_in_proper_case(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'code' => 'C-0001', 'name' => 'juan pérez', 'company_name' => 'construcciones del sur sl']);

        $this->assertSame('Juan Pérez', $customer->fresh()->name);
        $this->assertSame('Construcciones del Sur SL', $customer->fresh()->company_name);
    }
}
