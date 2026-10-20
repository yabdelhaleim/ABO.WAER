<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CancellationReason;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShippingZone;
use Database\Seeders\CancellationReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_number_generates_with_abo_prefix(): void
    {
        $number = Order::generateOrderNumber();

        $this->assertStringStartsWith('ABO-', $number);
    }

    public function test_order_can_be_created_with_enums_and_relationships(): void
    {
        $zone = ShippingZone::factory()->create();
        $gov = Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Cairo',
        ]);
        $order = Order::factory()->create([
            'governorate_id' => $gov->id,
            'governorate_name' => $gov->name,
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'unit_price' => 200,
            'quantity' => 2,
            'total_price' => 400,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'total_price' => 400,
        ]);

        // Relationships check
        $this->assertCount(1, $order->items);
        $this->assertEquals(OrderStatus::Pending, $order->status);
    }

    public function test_order_status_history_can_be_tracked(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $order->statusHistory()->create([
            'status' => OrderStatus::Confirmed,
            'comment' => 'Order confirmed via phone call',
            'changed_by_type' => 'Admin',
            'changed_by_id' => 1,
        ]);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'status' => 'confirmed',
        ]);
        $this->assertCount(1, $order->statusHistory);
    }

    public function test_cancellation_reasons_seeder_works(): void
    {
        $this->seed(CancellationReasonSeeder::class);

        $this->assertDatabaseHas('cancellation_reasons', [
            'reason' => 'Changed my mind',
        ]);
        $this->assertTrue(CancellationReason::count() >= 6);
    }
}
