<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Governorate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'governorate_id' => Governorate::factory(),
            'name' => fake()->name(),
            'phone' => '010'.fake()->numerify('########'),
            'area' => fake()->city(),
            'address' => fake()->streetAddress(),
            'notes' => fake()->optional()->sentence(),
            'is_default' => false,
        ];
    }

    /**
     * The address pre-selected at checkout.
     */
    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    /**
     * An address carrying a landmark or a delivery note.
     */
    public function withNotes(string $notes): static
    {
        return $this->state(fn (): array => ['notes' => $notes]);
    }
}
