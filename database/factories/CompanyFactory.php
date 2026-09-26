<?php

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+92300'.fake()->numerify('#######'),
            'whatsapp' => '+92300'.fake()->numerify('#######'),
            'city' => fake()->city(),
            'country' => 'Pakistan',
            'currency' => 'USD',
            'status' => CompanyStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => CompanyStatus::Suspended]);
    }
}
