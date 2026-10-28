<?php

namespace Tests\Feature\Console;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreSetting;
use App\Models\Variant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The auto-cancel of unpaid orders.
 *
 * The settings screen carries an "Auto-cancel unpaid orders" field and
 * a promise — "Unpaid orders are cancelled automatically after this
 * many hours" — that nothing kept. The hours were stored and read by
 * nobody, so an order that sat unpaid held its stock until an admin
 * noticed and cancelled it by hand.
 *
 * These tests pin down the promise: the command reads the window the
 * store set, cancels the orders that have outstayed it, puts their
 * stock back and leaves every other order alone.
 */
class CancelUnpaidOrdersTest extends TestCase
{
    use RefreshDatabase;

    // ─── The window ──────────────────────────────────────────────

    public function test_an_unpaid_order_older_than_the_window_is_cancelled(): void
    {
        StoreSetting::set('orders.autoCancelHours', 24);

        $order = $this->unpaidOrder(hoursOld: 30);

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $order->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status);
    }

    public function test_the_window_comes_from_the_store_settings(): void
    {
        StoreSetting::set('orders.autoCancelHours', 48);

        $order = $this->unpaidOrder(hoursOld: 30);

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        // 30 hours old is inside a 48-hour window.
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        StoreSetting::set('orders.autoCancelHours', 12);

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_an_order_inside_the_window_is_left_alone(): void
    {
        StoreSetting::set('orders.autoCancelHours', 24);

        $order = $this->unpaidOrder(hoursOld: 2);

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    // ─── What the command leaves alone ───────────────────────────

    public function test_an_order_the_store_already_confirmed_is_left_alone(): void
    {
        StoreSetting::set('orders.autoCancelHours', 24);

        $order = $this->unpaidOrder(hoursOld: 30, status: OrderStatus::Confirmed);

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_an_order_that_was_paid_is_left_alone(): void
    {
        StoreSetting::set('orders.autoCancelHours', 24);

        $order = $this->unpaidOrder(hoursOld: 30, paymentStatus: PaymentStatus::Paid);

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_an_order_that_was_already_cancelled_is_left_alone(): void
    {
        StoreSetting::set('orders.autoCancelHours', 24);

        $order = $this->unpaidOrder(hoursOld: 30, status: OrderStatus::Cancelled);

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);

        // The command did not touch it: it wrote no history of its own.
        $this->assertSame(0, $order->statusHistory()->count());
    }

    // ─── What the command does ───────────────────────────────────

    public function test_the_stock_goes_back_to_inventory(): void
    {
        StoreSetting::set('orders.autoCancelHours', 24);

        $order = $this->unpaidOrder(hoursOld: 30, quantity: 2);

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $this->assertSame(12, $order->items()->first()->variant->fresh()->quantity);
    }

    public function test_the_history_records_that_the_system_did_it(): void
    {
        StoreSetting::set('orders.autoCancelHours', 24);

        $order = $this->unpaidOrder(hoursOld: 30);

        $this->artisan('orders:cancel-unpaid')->assertSuccessful();

        $history = $order->fresh()->statusHistory()->first();

        $this->assertSame(OrderStatus::Cancelled, $history->status);
        $this->assertSame('System', $history->changed_by_type);
        $this->assertStringContainsString('automatically', (string) $history->comment);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        StoreSetting::set('orders.autoCancelHours', 24);

        $order = $this->unpaidOrder(hoursOld: 30);

        $this->artisan('orders:cancel-unpaid --dry-run')
            ->expectsOutputToContain('Would cancel '.$order->order_number)
            ->assertSuccessful();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(10, $order->items()->first()->variant->fresh()->quantity);
    }

    public function test_the_command_says_so_when_there_is_nothing_to_cancel(): void
    {
        StoreSetting::set('orders.autoCancelHours', 24);

        $this->artisan('orders:cancel-unpaid')
            ->expectsOutputToContain('No unpaid order is older than 24 hours.')
            ->assertSuccessful();
    }

    public function test_the_command_is_scheduled(): void
    {
        $schedule = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'orders:cancel-unpaid'));

        $this->assertCount(1, $schedule, 'the auto-cancel must run on a schedule');
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * An order that is still pending and still unpaid.
     */
    protected function unpaidOrder(
        int $hoursOld,
        OrderStatus $status = OrderStatus::Pending,
        PaymentStatus $paymentStatus = PaymentStatus::Pending,
        int $quantity = 1,
    ): Order {
        $variant = Variant::factory()->create(['quantity' => 10]);

        $order = Order::factory()->create([
            'status' => $status,
            'payment_status' => $paymentStatus,
            'created_at' => now()->subHours($hoursOld),
            'updated_at' => now()->subHours($hoursOld),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
        ]);

        return $order;
    }
}
