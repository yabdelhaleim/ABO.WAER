<?php

namespace Database\Factories;

use App\Models\Governorate;
use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Governorate>
 */
class GovernorateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shipping_zone_id' => ShippingZone::factory(),
            'name' => fake()->unique()->city(),
            'name_ar' => null,
            'fee' => null,
            'time_from' => null,
            'time_to' => null,
            'allows_cod' => true,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * A governorate with its own fee instead of inheriting the zone's.
     */
    public function withFee(float $fee): static
    {
        return $this->state(fn () => ['fee' => $fee]);
    }

    /**
     * A governorate that does not accept cash on delivery.
     */
    public function withoutCod(): static
    {
        return $this->state(fn () => ['allows_cod' => false]);
    }
}
