<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxRateTest extends TestCase
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

    public function test_a_new_company_starts_with_the_four_iva_rates(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/tax-rates')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.rate', 21)
            ->assertJsonPath('data.0.name', 'General');
    }

    public function test_a_rate_can_be_added_and_removed(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/tax-rates', ['name' => 'Special', 'rate' => 5])
            ->assertCreated()
            ->assertJsonPath('data.rate', 5)
            ->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/tax-rates/{$id}")
            ->assertOk();

        $this->assertNull(TaxRate::withoutGlobalScopes()->find($id));
    }

    public function test_the_last_rate_cannot_be_removed(): void
    {
        TaxRate::withoutGlobalScopes()->where('company_id', $this->owner->company_id)->delete();
        $only = TaxRate::withoutGlobalScopes()->create([
            'company_id' => $this->owner->company_id,
            'name' => 'Only',
            'rate' => 21,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/tax-rates/{$only->id}")
            ->assertStatus(422);

        $this->assertNotNull(TaxRate::withoutGlobalScopes()->find($only->id));
    }

    public function test_another_company_cannot_change_these_rates(): void
    {
        $id = TaxRate::query()->where('rate', 21)->value('id');
        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/app/tax-rates/{$id}")
            ->assertNotFound();
    }
}
