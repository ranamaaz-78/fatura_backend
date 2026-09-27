<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => 'C-'.fake()->unique()->numerify('####'),
            'name' => fake()->name(),
            'company_name' => fake()->company(),
            'phone' => fake()->numerify('6########'),
            'nif' => null,
            'nie' => null,
            'is_active' => true,
        ];
    }
}
