<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Governorate;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'ABO-'.date('Y').'-'.fake()->unique()->numberBetween(1000, 9999),
            'customer_name' => fake()->name(),
            'customer_phone' => '010'.fake()->numerify('########'),
            'customer_email' => fake()->safeEmail(),
            'governorate_id' => Governorate::factory(),
            'governorate_name' => 'Cairo',
            'area' => fake()->city(),
            'address' => fake()->streetAddress(),
            'subtotal' => 500.00,
            'shipping_fee' => 50.00,
            'discount_amount' => 0.00,
            'total' => 550.00,
            'payment_method' => 'cod',
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::Pending,
        ];
    }
}
