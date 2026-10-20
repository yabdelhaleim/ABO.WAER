<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'parent_id' => null,
            'name' => ucwords($name),
            'name_ar' => null,
            'slug' => Str::slug($name).'-'.fake()->unique()->bothify('##??'),
            'description' => null,
            'image' => null,
            'is_active' => true,
            'is_visible' => true,
            'show_in_homepage' => false,
            'sort_order' => 0,
        ];
    }
}
