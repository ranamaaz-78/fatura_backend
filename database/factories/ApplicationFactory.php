<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contact = fake()->name();
        $company = fake()->company();

        return [
            'company_name' => $company,
            'contact_name' => $contact,
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+92300'.fake()->numerify('#######'),
            'whatsapp' => fake()->boolean(70) ? '+92300'.fake()->numerify('#######') : null,
            'city' => fake()->city(),
            'country' => fake()->randomElement(['Pakistan', 'United Arab Emirates', 'Saudi Arabia', 'United Kingdom']),
            'business_type' => fake()->randomElement(['Retail', 'Services', 'Wholesale', 'Freelance', 'Restaurant', 'Construction']),
            'team_size' => fake()->randomElement(['1', '2-5', '6-10', '11-25', '25+']),
            'message' => fake()->boolean(60) ? fake()->sentence(12) : null,
            'plan_id' => null,
            'status' => fake()->randomElement([
                ApplicationStatus::New,
                ApplicationStatus::New,
                ApplicationStatus::Contacted,
                ApplicationStatus::Rejected,
            ]),
            'source' => 'website',
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'created_at' => fake()->dateTimeBetween('-45 days'),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ApplicationStatus::New]);
    }
}
