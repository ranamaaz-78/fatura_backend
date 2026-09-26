<?php

namespace Database\Factories;

use App\Enums\PlanInterval;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Starter', 'Growth', 'Scale', 'Enterprise']).' '.fake()->unique()->numberBetween(1, 9999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(8),
            'price' => fake()->randomElement([20, 45, 99]),
            'currency' => 'USD',
            'interval' => PlanInterval::Month,
            'features' => ['Unlimited invoices', 'Email support'],
            'max_users' => 5,
            'max_invoices' => null,
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function yearly(): static
    {
        return $this->state(fn () => ['interval' => PlanInterval::Year]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
