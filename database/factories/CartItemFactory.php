<?php

namespace Database\Factories;

use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    /**
     * Define the model's default state. Defaults to a guest cart keyed by a
     * generated session id, mirroring what CartService writes for visitors.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => null,
            'session_id' => Str::random(40),
            'product_id' => Variant::factory(),
            'variant_id' => fn (array $attributes) => Variant::factory()->create([
                'product_id' => $attributes['product_id'],
            ])->id,
            'quantity' => 1,
        ];
    }

    /**
     * Attach the line to a signed-in customer instead of a guest session.
     */
    public function forCustomer(Customer|int $customer): self
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer instanceof Customer ? $customer->id : $customer,
            'session_id' => null,
        ]);
    }

    /**
     * Pin the line to a specific variant, optionally overriding its quantity.
     */
    public function forVariant(Variant $variant, ?int $quantity = null): self
    {
        return $this->state(fn (): array => array_filter([
            'product_id' => $variant->product_id,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
        ], fn ($value) => $value !== null));
    }
}
