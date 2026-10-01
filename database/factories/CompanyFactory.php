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
            'tax_id' => 'B'.fake()->numerify('########'),
            'phone' => '+92300'.fake()->numerify('#######'),
            'whatsapp' => '+92300'.fake()->numerify('#######'),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postal_code' => fake()->numerify('#####'),
            'country' => 'Pakistan',
            'currency' => 'USD',
            // Complete by default so tests reach the workspace; use incomplete() for a company still in setup.
            'logo_path' => 'company-logos/factory.png',
            'status' => CompanyStatus::Active,
        ];
    }

    public function incomplete(): static
    {
        return $this->state(fn () => ['tax_id' => null, 'postal_code' => null, 'address' => null, 'logo_path' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => CompanyStatus::Suspended]);
    }
}
