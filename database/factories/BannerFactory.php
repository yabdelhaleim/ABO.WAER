<?php

namespace Database\Factories;

use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Banner>
 */
class BannerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'title_ar' => null,
            'subtitle' => fake()->sentence(6),
            'subtitle_ar' => null,
            'button_text' => 'Shop Now',
            'button_url' => '/shop',
            'image_path' => 'banners/placeholder.jpg',
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 10),
            'placement' => 'hero',
            'starts_at' => null,
            'ends_at' => null,
            'alt' => fake()->sentence(6),
            'alt_ar' => null,
            'mobile_image_path' => null,
            'text_color' => 'light',
            'text_position' => 'left',
            'overlay' => 'none',
            'link_type' => 'url',
            'link_target' => null,
        ];
    }
}
