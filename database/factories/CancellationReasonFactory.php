<?php

namespace Database\Factories;

use App\Models\CancellationReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CancellationReason>
 */
class CancellationReasonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reason' => fake()->sentence(3),
            'reason_ar' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }
}
