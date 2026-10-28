<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Events\OrderCancelled;
use App\Events\OrderCreated;
use App\Models\Admin;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\Variant;
use App\Notifications\CouponExpiringAdminNotification;
use App\Notifications\CustomerCancellationAdminNotification;
use App\Notifications\LowStockAdminNotification;
use App\Notifications\NewOrderAdminNotification;
use App\Services\Admin\OrderService as AdminOrderService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The bell's switches, end to end.
 *
 * The notifications panel of the settings screen was a row of switches
 * that nothing read: the store could turn "Low stock" on or off and the
 * shelf emptying would ring nobody's bell, and "Shipment failed" sat
 * there promising a courier callback this application cannot receive.
 *
 * These tests pin down what each switch now does — and that a switch
 * that is off really is quiet.
 */
class NotificationSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Store Owner',
            'email' => 'owner@store.com',
            'password' => 'password',
            'is_active' => true,
            'role' => AdminRole::Owner->value,
        ]);

        // The settings live in a static memo that outlives the rollback
        // between tests.
        StoreSetting::flush();
    }

    protected function tearDown(): void
    {
        StoreSetting::flush();

        parent::tearDown();
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin');
    }

    // ─── New order ───────────────────────────────────────────────

    public function test_a_new_order_rings_the_bell(): void
    {
        Notification::fake();

        event(new OrderCreated($this->order()));

        Notification::assertSentTo($this->admin, NewOrderAdminNotification::class);
    }

    public function test_the_new_order_switch_off_keeps_the_bell_quiet(): void
    {
        StoreSetting::set('notifications.newOrder', false);

        Notification::fake();

        event(new OrderCreated($this->order()));

        Notification::assertNotSentTo($this->admin, NewOrderAdminNotification::class);
    }

    // ─── Low stock ───────────────────────────────────────────────

    public function test_an_order_that_empties_the_shelf_rings_the_low_stock_bell(): void
    {
        StoreSetting::set('inventory.lowStockThreshold', 5);

        Notification::fake();

        $order = $this->order(quantity: 6, stock: 10);

        event(new OrderCreated($order));

        Notification::assertSentTo(
            $this->admin,
            LowStockAdminNotification::class,
            function (LowStockAdminNotification $notification): bool {
                $data = $notification->toArray($this->admin);

                $this->assertSame('stock', $data['type']);
                $this->assertSame('coral', $data['tone']);
                $this->assertStringContainsString('Low stock', $data['title']);
                $this->assertStringContainsString('4 left', $data['title']);

                return true;
            },
        );
    }

    public function test_an_order_that_leaves_stock_above_the_threshold_says_nothing(): void
    {
        StoreSetting::set('inventory.lowStockThreshold', 5);

        Notification::fake();

        $order = $this->order(quantity: 2, stock: 10);

        event(new OrderCreated($order));

        Notification::assertNotSentTo($this->admin, LowStockAdminNotification::class);
    }

    public function test_the_low_stock_switch_off_keeps_the_bell_quiet(): void
    {
        StoreSetting::set('inventory.lowStockThreshold', 5);
        StoreSetting::set('notifications.lowStock', false);

        Notification::fake();

        $order = $this->order(quantity: 6, stock: 10);

        event(new OrderCreated($order));

        Notification::assertNotSentTo($this->admin, LowStockAdminNotification::class);
    }

    // ─── Customer cancellation ────────────────────────────────────

    public function test_a_customer_cancellation_rings_the_bell(): void
    {
        Notification::fake();

        $order = $this->order();

        event(new OrderCancelled($order, 'Customer', 1));

        Notification::assertSentTo(
            $this->admin,
            CustomerCancellationAdminNotification::class,
            function (CustomerCancellationAdminNotification $notification): bool {
                $data = $notification->toArray($this->admin);

                $this->assertSame('returns', $data['type']);
                $this->assertStringContainsString('cancelled', $data['title']);

                return true;
            },
        );
    }

    public function test_an_admin_cancellation_does_not_ring_the_bell(): void
    {
        Notification::fake();

        $order = $this->order();

        event(new OrderCancelled($order, 'Admin', $this->admin->id));

        Notification::assertNotSentTo($this->admin, CustomerCancellationAdminNotification::class);
    }

    public function test_the_customer_cancellation_switch_off_keeps_the_bell_quiet(): void
    {
        StoreSetting::set('notifications.customerCancellation', false);

        Notification::fake();

        $order = $this->order();

        event(new OrderCancelled($order, 'Customer', 1));

        Notification::assertNotSentTo($this->admin, CustomerCancellationAdminNotification::class);
    }

    public function test_cancelling_an_order_is_what_rings_the_bell(): void
    {
        Notification::fake();

        $order = $this->order();

        app(AdminOrderService::class)->cancelOrder(
            order: $order,
            changedById: 1,
            changedByType: 'Customer',
        );

        Notification::assertSentTo($this->admin, CustomerCancellationAdminNotification::class);
    }

    // ─── Coupon expiry ───────────────────────────────────────────

    public function test_the_coupon_report_stays_quiet_when_the_switch_is_off(): void
    {
        StoreSetting::set('notifications.couponExpiring', false);

        Coupon::factory()->create(['expires_at' => now()->addHours(2)]);

        Notification::fake();

        $this->artisan('coupons:report-expiring')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_coupon_about_to_expire_is_reported(): void
    {
        StoreSetting::set('notifications.couponExpiring', true);

        $coupon = Coupon::factory()->create(['expires_at' => now()->addHours(2)]);

        Notification::fake();

        $this->artisan('coupons:report-expiring')->assertSuccessful();

        Notification::assertSentTo(
            $this->admin,
            CouponExpiringAdminNotification::class,
            function (CouponExpiringAdminNotification $notification) use ($coupon): bool {
                $data = $notification->toArray($this->admin);

                $this->assertSame('coupons', $data['type']);
                $this->assertSame('amber', $data['tone']);
                $this->assertStringContainsString($coupon->code, $data['title']);

                return true;
            },
        );
    }

    public function test_a_coupon_is_reported_once(): void
    {
        StoreSetting::set('notifications.couponExpiring', true);

        Coupon::factory()->create(['expires_at' => now()->addHours(2)]);

        $this->artisan('coupons:report-expiring')->assertSuccessful();

        Notification::fake();

        $this->artisan('coupons:report-expiring')
            ->expectsOutputToContain('0 coupon(s) reported.')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_coupon_report_is_scheduled(): void
    {
        $schedule = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'coupons:report-expiring'));

        $this->assertCount(1, $schedule, 'the coupon expiry report must run on a schedule');
    }

    // ─── The switch that is gone ─────────────────────────────────

    public function test_the_settings_payload_has_no_shipment_failed_switch(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.settings.index'));

        $response->assertOk()->assertViewIs('admin.settings.index');

        preg_match(
            '/<script type="application\/json" data-abo-admin-settings-payload>(.+?)<\/script>/s',
            $response->getContent() ?: '',
            $matches,
        );

        $this->assertArrayHasKey(1, $matches, 'the settings screen must publish its payload');

        $payload = json_decode($matches[1], true);

        $this->assertArrayNotHasKey('shipmentFailed', $payload['notifications']);
        $this->assertArrayNotHasKey('returnRequest', $payload['notifications']);
        $this->assertArrayHasKey('customerCancellation', $payload['notifications']);
    }

    public function test_the_settings_screen_shows_no_shipment_failed_toggle(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.settings.index'));

        $html = $response->getContent() ?: '';

        $this->assertStringNotContainsString('Shipment failed', $html);
        $this->assertStringContainsString('Customer cancellation', $html);
    }

    public function test_the_bell_has_a_tab_for_the_coupon_alerts(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.dashboard'));

        $response->assertOk();

        $html = $response->getContent() ?: '';

        $this->assertStringContainsString('data-abo-admin-bell-filter="coupons"', $html);
    }

    /**
     * The bell's data has to reach the browser.
     *
     * The topbar used to push its script onto the `head` stack — but it
     * is included from the layout's body, so the head was already
     * written and the script was dropped. The bell woke up with no
     * payload, no endpoints and no VAPID key: the list stayed empty,
     * the poll never ran and nothing ever rang.
     */
    public function test_the_bell_is_handed_its_data(): void
    {
        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->getContent() ?: '';

        $this->assertStringContainsString('window.INITIAL_ADMIN_NOTIFICATIONS', $html);
        $this->assertStringContainsString('window.ADMIN_NOTIFICATIONS_ENDPOINTS', $html);
        $this->assertStringContainsString(json_encode(route('admin.notifications.index')), $html);
        $this->assertStringContainsString('window.ADMIN_NOTIFICATIONS_POLL_MS', $html);
    }

    public function test_the_bell_is_told_whether_to_ring(): void
    {
        StoreSetting::set('notifications.browserSound', false);

        $response = $this->actingAsAdmin()->get(route('admin.dashboard'));

        $html = $response->getContent() ?: '';

        $this->assertStringContainsString('window.ADMIN_NOTIFICATIONS_SOUND = false', $html);

        StoreSetting::set('notifications.browserSound', true);

        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->getContent() ?: '';

        $this->assertStringContainsString('window.ADMIN_NOTIFICATIONS_SOUND = true', $html);
    }

    public function test_a_stale_page_cannot_bring_the_dead_switch_back(): void
    {
        $this->actingAsAdmin()
            ->putJson(route('admin.settings.update'), $this->settingsBody([
                'notifications' => [
                    'shipmentFailed' => true,
                    'returnRequest' => true,
                ],
            ]))
            ->assertOk();

        $this->assertNull(StoreSetting::get('notifications.shipmentFailed'));
        $this->assertNull(StoreSetting::get('notifications.returnRequest'));
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * An order with a line on it.
     *
     * The stock comes off the shelf with the order, the way the
     * checkout takes it — the low-stock bell reads the shelf, not the
     * order.
     */
    protected function order(int $quantity = 1, int $stock = 10): Order
    {
        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => $stock,
        ]);

        $order = Order::factory()->create();

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
        ]);

        $variant->decrement('quantity', $quantity);

        return $order;
    }

    /**
     * The settings the screen sends when nothing was changed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function settingsBody(array $overrides = []): array
    {
        return array_replace_recursive([
            'store' => [
                'name' => 'ABO',
                'phone' => '',
                'whatsapp' => '',
                'email' => '',
                'address' => '',
                'logo' => '',
                'favicon' => '',
                'social' => ['instagram' => '', 'facebook' => '', 'tiktok' => ''],
                'maintenance' => false,
            ],
            'orders' => [
                'minimum' => 0,
                'autoCancelHours' => 24,
                'guestCheckout' => true,
                'prefix' => 'ABO-',
            ],
            'inventory' => [
                'lowStockThreshold' => 5,
                'hideOutOfStock' => false,
            ],
            'reasons' => [],
            'receipts' => [
                'showLogo' => true,
                'headerText' => 'ABO Store',
                'footerNote' => '',
                'fields' => [
                    'orderNumber' => true,
                    'date' => true,
                    'customerPhone' => true,
                    'address' => false,
                    'paymentMethod' => true,
                    'shippingFee' => true,
                ],
                'paper' => 'a4',
            ],
            'notifications' => [
                'newOrder' => true,
                'lowStock' => true,
                'customerCancellation' => true,
                'couponExpiring' => false,
                'browserSound' => true,
            ],
        ], $overrides);
    }
}
