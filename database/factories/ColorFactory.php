<?php

namespace Database\Factories;

use App\Models\Color;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Color>
 */
class ColorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'name_ar' => null,
            'hex_code' => strtoupper(fake()->unique()->hexColor()),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
