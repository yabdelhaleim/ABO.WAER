<?php

namespace Database\Factories;

use App\Models\ProductColor;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_color_id' => ProductColor::factory(),
            'path' => 'images/streetwear-hero.jpg',
            'thumb_path' => null,
            'sort_order' => 0,
        ];
    }
}
