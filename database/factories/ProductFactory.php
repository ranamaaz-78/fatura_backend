<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Support\Barcode;
use App\Support\Pricing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $buying = fake()->numberBetween(100, 50_000);
        $margin = fake()->randomElement([20, 25, 30, 40, 50]);
        $iva = fake()->randomElement([0, 4, 10, 21]);

        return [
            'company_id' => Company::factory(),
            'category_id' => null,
            'sr_number' => fake()->unique()->numerify('YKF-######'),
            'article' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'brand' => fake()->company(),
            'image_code' => fake()->bothify('IMG-####'),
            'barcode' => Barcode::randomEan13(),
            'barcode_generated' => false,
            'quantity' => fake()->numberBetween(0, 200),
            'minimum_stock' => fake()->numberBetween(0, 10),
            'buying_price' => $buying,
            'selling_price' => Pricing::sellingPrice($buying, $margin, $iva),
            'margin_percent' => $margin,
            'iva_percent' => $iva,
        ];
    }

    public function lowStock(): static
    {
        return $this->state(fn () => ['quantity' => 2, 'minimum_stock' => 5]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['quantity' => 0]);
    }

    public function generatedBarcode(): static
    {
        return $this->state(fn () => ['barcode_generated' => true]);
    }
}
