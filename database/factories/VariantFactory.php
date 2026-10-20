<?php

namespace Database\Factories;

use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'color_id' => Color::factory(),
            'size_id' => Size::factory(),
            'sku' => strtoupper(Str::random(10)),
            'price' => fake()->randomFloat(2, 200, 3000),
            'cost' => fake()->randomFloat(2, 100, 1500),
            'discount_price' => null,
            'quantity' => fake()->numberBetween(0, 25),
        ];
    }

    public function inStock(): self
    {
        return $this->state(fn () => ['quantity' => fake()->numberBetween(1, 50)]);
    }

    public function outOfStock(): self
    {
        return $this->state(fn () => ['quantity' => 0]);
    }
}
