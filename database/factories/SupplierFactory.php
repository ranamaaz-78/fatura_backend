<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => 'S-'.fake()->unique()->numerify('####'),
            'name' => fake()->company(),
            'company_name' => fake()->company(),
            'phone' => fake()->numerify('6########'),
            'nif' => null,
            'nie' => null,
            'is_active' => true,
        ];
    }
}
