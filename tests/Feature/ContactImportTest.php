<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactImportTest extends TestCase
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

    private function customerRows(): array
    {
        return [
            ['name' => 'ana ruiz', 'company_name' => 'Ruiz SL', 'phone' => '612 000 111', 'email' => 'ANA@Ruiz.ES', 'nif' => 'b12345678', 'address' => 'Calle Sol 3', 'remarks' => 'Pays at month end'],
            ['name' => 'Luis Perez'],
        ];
    }

    public function test_clients_are_imported_with_their_codes_and_tidy_values(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers/import', ['rows' => $this->customerRows()])
            ->assertCreated()
            ->assertJsonPath('data.created', 2);

        $ana = Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->where('phone', '612 000 111')->firstOrFail();
        $this->assertSame('C-0001', $ana->code);
        $this->assertSame('Ana Ruiz', $ana->name);
        $this->assertSame('ana@ruiz.es', $ana->email);
        $this->assertSame('B12345678', $ana->nif);
        $this->assertNull($ana->nie);
        $this->assertTrue($ana->is_active);
        $this->assertSame('C-0002', Customer::withoutGlobalScopes()->where('name', 'Luis Perez')->value('code'));

        // The next one made by hand carries on from the import.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers', ['name' => 'Carla'])
            ->assertJsonPath('data.code', 'C-0003');
    }

    public function test_one_bad_row_stops_the_whole_client_import(): void
    {
        $rows = $this->customerRows();
        $rows[1] = ['name' => '', 'email' => 'not-an-email'];

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers/import', ['rows' => $rows])
            ->assertStatus(422)
            ->assertJsonPath('errors.rows.0.row', 1)
            ->assertJsonCount(2, 'errors.rows.0.messages');

        $this->assertSame(0, Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_a_phone_number_already_saved_or_repeated_in_the_file_is_refused(): void
    {
        Customer::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'C-0001', 'name' => 'Old Friend', 'phone' => '600-111-222']);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers/import', ['rows' => [
                ['name' => 'Same number as a saved one', 'phone' => '600 111 222'],
                ['name' => 'First', 'phone' => '700000000'],
                ['name' => 'Second', 'phone' => '700 000 000'],
            ]])
            ->assertStatus(422);

        $rows = collect($response->json('errors.rows'))->keyBy('row');
        $this->assertSame([0, 2], $rows->keys()->all());
        $this->assertStringContainsString('Old Friend', $rows[0]['messages'][0]);
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_the_same_number_in_another_company_is_not_a_clash(): void
    {
        $other = Company::factory()->create();
        Customer::withoutGlobalScopes()->create(['company_id' => $other->id, 'code' => 'C-0001', 'name' => 'Elsewhere', 'phone' => '600111222']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers/import', ['rows' => [['name' => 'Mine', 'phone' => '600111222']]])
            ->assertCreated();

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('company_id', $other->id)->count());
    }

    public function test_suppliers_are_imported_with_their_codes(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/suppliers/import', ['rows' => [
                ['name' => 'Textiles Norte', 'company_name' => 'Norte SA', 'phone' => '910000000', 'email' => 'Ventas@Norte.es', 'nif' => 'a1234567b', 'remarks' => 'Ask for Marta'],
                ['name' => 'Cables Sur'],
            ]])
            ->assertCreated()
            ->assertJsonPath('data.created', 2);

        $norte = Supplier::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Textiles Norte')->firstOrFail();
        $this->assertSame('S-0001', $norte->code);
        $this->assertSame('ventas@norte.es', $norte->email);
        $this->assertSame('A1234567B', $norte->nif);
        $this->assertSame('Ask for Marta', $norte->remarks);
        $this->assertSame('S-0002', Supplier::withoutGlobalScopes()->where('name', 'Cables Sur')->value('code'));
    }

    public function test_a_supplier_name_already_in_use_or_repeated_is_refused(): void
    {
        Supplier::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'code' => 'S-0001', 'name' => 'Textiles Norte']);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/suppliers/import', ['rows' => [
                ['name' => 'TEXTILES NORTE'],
                ['name' => 'Cables Sur'],
                ['name' => 'cables sur'],
            ]])
            ->assertStatus(422);

        $this->assertSame([0, 2], collect($response->json('errors.rows'))->pluck('row')->all());
        $this->assertSame(1, Supplier::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_a_member_without_the_permission_cannot_import(): void
    {
        $cashier = User::factory()->create([
            'company_id' => $this->company->id,
            'role' => 'staff',
            'permissions' => ['customers.view'],
        ]);

        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/app/customers/import', ['rows' => [['name' => 'No']]])
            ->assertForbidden();
        $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/app/suppliers/import', ['rows' => [['name' => 'No']]])
            ->assertForbidden();

        $this->assertSame(0, Customer::withoutGlobalScopes()->count());
    }

    public function test_an_empty_or_oversized_file_is_refused(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/customers/import', ['rows' => []])->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/suppliers/import', ['rows' => array_fill(0, 2001, ['name' => 'X'])])
            ->assertStatus(422);
    }
}
