<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\RecargoRate;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecargoRateTest extends TestCase
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

    public function test_a_new_company_starts_with_the_three_legal_recargo_rates(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/recargo-rates')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.rate', 5.2)
            ->assertJsonPath('data.1.rate', 1.4)
            ->assertJsonPath('data.2.rate', 0.5);
    }

    public function test_a_rate_can_be_added_edited_and_removed(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/recargo-rates', ['name' => 'Tabaco', 'rate' => 1.75])
            ->assertCreated()
            ->assertJsonPath('data.rate', 1.75)
            ->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/recargo-rates/{$id}", ['name' => 'Tabaco 2', 'rate' => 2])
            ->assertOk()
            ->assertJsonPath('data.name', 'Tabaco 2')
            ->assertJsonPath('data.rate', 2);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/recargo-rates/{$id}")
            ->assertOk();

        $this->assertNull(RecargoRate::withoutGlobalScopes()->find($id));
    }

    public function test_a_rate_needs_a_name_and_a_percent_between_0_and_100(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/recargo-rates', ['name' => '', 'rate' => 150])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'rate']);
    }

    public function test_the_same_percent_cannot_be_added_twice_but_another_company_may_use_it(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/recargo-rates', ['name' => 'Again', 'rate' => 5.2])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rate']);

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $second = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        // Its own defaults already hold 5.2, so use a value neither company has yet.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/recargo-rates', ['name' => 'Mine', 'rate' => 3])
            ->assertCreated();
        $this->actingAs($second, 'sanctum')
            ->postJson('/api/app/recargo-rates', ['name' => 'Theirs', 'rate' => 3])
            ->assertCreated();
    }

    public function test_every_rate_may_be_removed_because_nothing_depends_on_it(): void
    {
        $ids = RecargoRate::query()->pluck('id');

        foreach ($ids as $id) {
            $this->actingAs($this->owner, 'sanctum')
                ->deleteJson("/api/app/recargo-rates/{$id}")
                ->assertOk();
        }

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/recargo-rates')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_another_company_cannot_see_or_change_these_rates(): void
    {
        $id = RecargoRate::query()->where('rate', 5.2)->value('id');
        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->patchJson("/api/app/recargo-rates/{$id}", ['name' => 'Hacked', 'rate' => 9])
            ->assertNotFound();
        $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/app/recargo-rates/{$id}")
            ->assertNotFound();

        $this->assertSame('General', RecargoRate::withoutGlobalScopes()->find($id)->name);
    }

    public function test_the_recargo_rates_are_behind_the_subscription_check(): void
    {
        $expired = Company::factory()->create();
        $user = User::factory()->businessAdmin()->create(['company_id' => $expired->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/app/recargo-rates')
            ->assertStatus(402);
    }
}
