<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeaturedPlanTest extends TestCase
{
    use RefreshDatabase;

    private function featuredNames(): array
    {
        return Plan::where('is_featured', true)->orderBy('name')->pluck('name')->all();
    }

    public function test_featuring_a_plan_clears_the_badge_from_the_others(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $starter = Plan::factory()->create(['name' => 'Starter', 'is_featured' => true]);
        $pro = Plan::factory()->create(['name' => 'Pro', 'is_featured' => false]);

        $this->patchJson("/api/admin/plans/{$pro->id}", ['is_featured' => true])->assertOk();

        $this->assertSame(['Pro'], $this->featuredNames());
        $this->assertFalse($starter->fresh()->is_featured);
    }

    public function test_a_new_featured_plan_takes_over(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        Plan::factory()->create(['name' => 'Starter', 'is_featured' => true]);

        $this->postJson('/api/admin/plans', [
            'name' => 'Growth', 'price' => 49, 'interval' => 'month', 'is_featured' => true,
        ])->assertCreated();

        $this->assertSame(['Growth'], $this->featuredNames());
    }

    public function test_saving_a_plan_without_featuring_it_leaves_the_badge_alone(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        Plan::factory()->create(['name' => 'Starter', 'is_featured' => true]);
        $pro = Plan::factory()->create(['name' => 'Pro', 'is_featured' => false]);

        $this->patchJson("/api/admin/plans/{$pro->id}", ['name' => 'Pro Plus', 'is_featured' => false])->assertOk();

        $this->assertSame(['Starter'], $this->featuredNames());
    }
}
