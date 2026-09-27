<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
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

    public function test_a_client_gets_the_next_code_and_only_its_company_sees_it(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers', [
                'name' => 'Ana Ruiz',
                'company_name' => 'Ruiz SL',
                'phone' => '612000111',
                'nif' => '12345678Z',
                'nie' => 'Y1234567X',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'C-0001')
            ->assertJsonPath('data.company_name', 'Ruiz SL');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers', ['name' => 'Luis'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'C-0002');

        Customer::factory()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Hidden']);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/customers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ana Ruiz');
    }

    public function test_editing_changes_the_label_and_keeps_the_code(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers', ['name' => 'Ana Ruiz'])
            ->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/customers/{$id}", [
                'name' => 'Ana Ruiz Lopez',
                'phone' => '600111222',
                'company_name' => '',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ana Ruiz Lopez')
            ->assertJsonPath('data.code', 'C-0001')
            ->assertJsonPath('data.company_name', null);

        $this->assertSame('C-0001', Customer::find($id)->code);
    }

    public function test_a_client_can_be_deleted_and_another_company_cannot_reach_it(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers', ['name' => 'Ana Ruiz'])
            ->json('data.id');

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/app/customers/{$id}", ['name' => 'Stolen'])
            ->assertNotFound();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/customers/{$id}")
            ->assertOk();

        $this->assertSame(0, Customer::count());
    }

    public function test_a_phone_search_matches_only_the_telephone(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers', [
                'name' => 'Ana Ruiz',
                'phone' => '612000111',
                'company_name' => 'Ruiz',
                'nif' => 'B1',
            ])
            ->assertCreated();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers', [
                'name' => '612 Shop',
                'phone' => '600111222',
            ])
            ->assertCreated();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/customers?phone=612000&status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ana Ruiz')
            ->assertJsonPath('data.0.phone', '612000111');
    }

    public function test_a_client_can_be_switched_inactive_and_drops_out_of_the_active_list(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/customers', ['name' => 'Ana Ruiz'])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/customers/{$id}/active", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/customers?status=active')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/customers?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'C-0001');

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/app/customers/{$id}/active", ['is_active' => true])
            ->assertNotFound();
    }
}
