<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // All three identifiers are unique columns, so each draw has to be
            // unique across the whole factory run, not merely within a test.
            'email' => fake()->unique()->safeEmail(),
            'phone' => '010'.fake()->unique()->numerify('########'),
            'whatsapp' => null,
            'password' => 'password123',
            'accepts_promotions' => false,
            'is_blocked' => false,
        ];
    }

    /**
     * An account the store front must refuse to serve.
     */
    public function blocked(string $reason = 'Fraudulent orders'): static
    {
        return $this->state(fn (): array => [
            'is_blocked' => true,
            'block_reason' => $reason,
        ]);
    }
}
