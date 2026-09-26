<?php

namespace Database\Factories;

use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plan = Plan::factory();

        return [
            'company_id' => Company::factory(),
            'plan_id' => $plan,
            'plan_name' => 'Starter',
            'plan_price' => 20,
            'plan_currency' => 'USD',
            'plan_interval' => PlanInterval::Month,
            'plan_features' => ['Unlimited invoices'],
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addMonthNoOverflow(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
