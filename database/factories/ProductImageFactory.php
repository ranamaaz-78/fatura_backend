<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ProductImage;
use App\Support\ImageName;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'company_id' => Company::factory(),
            'created_by' => null,
            'name' => $name,
            'name_key' => ImageName::key($name),
            'path' => 'product-images/1/'.Str::uuid().'.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(2000, 400000),
        ];
    }
}
