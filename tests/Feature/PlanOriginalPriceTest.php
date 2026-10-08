<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanOriginalPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plan_can_show_a_higher_original_price(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->postJson('/api/admin/plans', [
            'name' => 'Starter', 'price' => 20, 'original_price' => 30, 'interval' => 'month',
        ])->assertCreated()->assertJsonPath('data.original_price', 30)->assertJsonPath('data.price', 20);

        $this->getJson('/api/public/plans')->assertOk()->assertJsonPath('data.0.original_price', 30);
    }

    public function test_the_original_price_must_be_higher_than_the_price(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $plan = Plan::factory()->create(['price' => 20]);

        $this->patchJson("/api/admin/plans/{$plan->id}", ['price' => 20, 'original_price' => 20])
            ->assertStatus(422)->assertJsonValidationErrors('original_price');
        $this->patchJson("/api/admin/plans/{$plan->id}", ['original_price' => 15])
            ->assertStatus(422)->assertJsonValidationErrors('original_price');
    }

    public function test_the_discount_can_be_removed(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $plan = Plan::factory()->create(['price' => 20, 'original_price' => 30]);

        $this->patchJson("/api/admin/plans/{$plan->id}", ['original_price' => null])
            ->assertOk()->assertJsonPath('data.original_price', null);
    }
}
