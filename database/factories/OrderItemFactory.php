<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'variant_id' => Variant::factory(),
            'product_name' => fake()->words(2, true),
            'color_name' => 'Black',
            'size_name' => 'L',
            'sku' => 'PROD-C1-S1',
            'unit_price' => 250.00,
            'quantity' => 2,
            'total_price' => 500.00,
        ];
    }
}
