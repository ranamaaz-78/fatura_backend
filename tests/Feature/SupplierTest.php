<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierTest extends TestCase
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

    public function test_a_supplier_gets_the_next_code_and_only_its_company_sees_it(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/suppliers', [
                'name' => 'Acme Parts',
                'company_name' => 'Acme SL',
                'phone' => '612000111',
                'nif' => 'B12345678',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'S-0001')
            ->assertJsonPath('data.company_name', 'Acme SL')
            ->assertJsonPath('data.is_active', true);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/suppliers', ['name' => 'Beta Tools'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'S-0002');

        Supplier::factory()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Hidden']);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/suppliers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Acme Parts');
    }

    public function test_editing_changes_the_label_and_keeps_the_code(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/suppliers', ['name' => 'Acme Parts'])
            ->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/suppliers/{$id}", [
                'name' => 'Acme Parts Ltd',
                'phone' => '600111222',
                'company_name' => '',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Parts Ltd')
            ->assertJsonPath('data.code', 'S-0001')
            ->assertJsonPath('data.company_name', null);

        $this->assertSame('S-0001', Supplier::find($id)->code);
    }

    public function test_a_supplier_can_be_deleted_and_another_company_cannot_reach_it(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/suppliers', ['name' => 'Acme Parts'])
            ->json('data.id');

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/app/suppliers/{$id}", ['name' => 'Stolen'])
            ->assertNotFound();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/suppliers/{$id}")
            ->assertOk();

        $this->assertSame(0, Supplier::count());
    }

    public function test_a_supplier_can_be_switched_inactive_and_drops_out_of_the_active_list(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/suppliers', ['name' => 'Acme Parts'])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/suppliers/{$id}/active", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/suppliers?status=active')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/suppliers?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'S-0001');
    }
}
