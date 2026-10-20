<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'category_id' => Category::factory(),
            'name' => ucwords($name),
            'name_ar' => null,
            'slug' => Str::slug($name).'-'.fake()->unique()->bothify('##??'),
            'description' => fake()->sentence(),
            'is_archived' => false,
            'is_featured' => false,
        ];
    }

    public function archived(): self
    {
        return $this->state(fn () => ['is_archived' => true]);
    }
}
