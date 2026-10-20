<?php

namespace Database\Factories;

use App\Models\Size;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Size>
 */
class SizeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['S', 'M', 'L', 'XL', 'XXL']),
            'group' => 'tops',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
