<?php

namespace Tests\Feature\Admin;

use App\Events\OrderCreated;
use App\Listeners\NotifyAdminsOfNewOrder;
use App\Models\Admin;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\Variant;
use App\Notifications\NewOrderAdminNotification;
use App\Services\Admin\AdminNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/**
 * End-to-end coverage for Phase 8 · Step 3 — the admin order
 * notification pipeline:
 *
 *  1. OrderService::placeOrder dispatches OrderCreated on success.
 *  2. NotifyAdminsOfNewOrder fans out a NewOrderAdminNotification
 *     through the database channel to every active admin, and skips
 *     the deactivated ones.
 *  3. The bell endpoints (GET, PATCH, POST) hydrate the badge and the
 *     list, mark individual rows read, and wipe the unread counter.
 *
 * The dispatch / listener / notifiable chain is verified through
 * Event::fake() and Notification::fake() so the test does not need
 * an extra observer pattern. The HTTP surface is verified against the
 * real admin guard so the route binding, JSON shape and read-flag
 * persistence are all checked in one place.
 */
class AdminOrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Admin $inactiveAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Lead Admin',
            'email' => 'lead_admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        // Same row, but flagged deactivated — must never receive
        // a notification, even when its notifications table is
        // otherwise queryable.
        $this->inactiveAdmin = Admin::create([
            'name' => 'Old Admin',
            'email' => 'old_admin@store.com',
            'password' => 'password',
            'is_active' => false,
        ]);
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin');
    }

    /**
     * Build a customer + product + cart line that's ready to be
     * posted to the checkout endpoint. Returns the variant so the
     * test can assert stock decremented.
     */
    private function cartReadyForCheckout(): array
    {
        Customer::factory()->create();

        $customer = Customer::query()->firstOrFail();

        $zone = ShippingZone::factory()->create(['fee' => 50.00]);
        $governorate = Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Cairo',
            'fee' => 45.00,
        ]);

        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 300.00,
        ]);

        CartItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        return [$customer, $variant, $governorate];
    }

    // ─── Event & notification fan-out ─────────────────────────────

    public function test_order_creation_dispatches_order_created_event(): void
    {
        Event::fake([OrderCreated::class]);

        [$customer, $variant] = $this->cartReadyForCheckout();

        $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Tester',
                'phone' => '01012345678',
                'governorate' => 'Cairo',
                'area' => 'Nasr City',
                'address' => 'Tahrir St',
                'payment_method' => 'cod',
            ])
            ->assertCreated();

        Event::assertDispatched(
            OrderCreated::class,
            fn (OrderCreated $event): bool => $event->order instanceof Order
                && $event->order->total > 0
                && str_starts_with($event->order->order_number, 'ABO-'),
        );
    }

    public function test_notify_admins_listener_is_auto_discovered_for_order_created(): void
    {
        // Laravel 11+ auto-discovers listeners whose handle() method
        // type-hints an Event class, but wraps the registration in a
        // Closure that resolves the listener at dispatch time. Rather
        // than introspect the wrapper, we fire the event directly and
        // confirm the listener reacts: a second admin who only exists
        // at dispatch time must end up with a notifications row.
        $onTheFlyAdmin = Admin::create([
            'name' => 'Late Admin',
            'email' => 'late_admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $order = Order::factory()->create();

        event(new OrderCreated($order));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $onTheFlyAdmin->id,
            'notifiable_type' => Admin::class,
            'type' => NewOrderAdminNotification::class,
        ]);

        $this->assertSame(1, $onTheFlyAdmin->fresh()->unreadNotifications()->count());
    }

    public function test_a_successful_order_persists_a_database_notification_for_every_active_admin(): void
    {
        Notification::fake();

        [$customer, $variant] = $this->cartReadyForCheckout();

        $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Tester',
                'phone' => '01012345678',
                'governorate' => 'Cairo',
                'area' => 'Nasr City',
                'address' => 'Tahrir St',
                'payment_method' => 'cod',
            ])
            ->assertCreated();

        // The active admin receives a database-channel notification.
        Notification::assertSentTo(
            $this->admin,
            NewOrderAdminNotification::class,
            function (NewOrderAdminNotification $notification, array $channels, object $notifiable): bool {
                $this->assertContains('database', $channels);
                $this->assertContains(WebPushChannel::class, $channels);

                $data = $notification->toArray($notifiable);
                $this->assertSame('orders', $data['type']);
                $this->assertSame('lime', $data['tone']);
                $this->assertStringStartsWith('New order #', $data['title']);
                $this->assertStringContainsString('EGP', $data['title']);
                $this->assertNotEmpty($data['time']);
                $this->assertStringContainsString($notification->order->order_number, $data['href']);
                $this->assertSame($notification->order->id, $data['order_id']);

                return true;
            },
        );

        // The inactive admin is skipped entirely.
        Notification::assertNotSentTo(
            $this->inactiveAdmin,
            NewOrderAdminNotification::class,
        );
    }

    // ─── HTTP bell surface ────────────────────────────────────────

    public function test_index_endpoint_returns_the_unread_count_and_serialized_rows(): void
    {
        [$customer, $variant] = $this->cartReadyForCheckout();

        $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Tester',
                'phone' => '01012345678',
                'governorate' => 'Cairo',
                'area' => 'Nasr City',
                'address' => 'Tahrir St',
                'payment_method' => 'cod',
            ])
            ->assertCreated();

        // The order placed above fan-outs a single notification; the
        // bell's index endpoint reflects the bell-state faithful to
        // what's in the database.
        $response = $this->actingAsAdmin()
            ->getJson(route('admin.notifications.index'))
            ->assertOk();

        $response->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications');

        $row = $response->json('notifications.0');

        $this->assertNotEmpty($row['id']);
        $this->assertSame('orders', $row['type']);
        $this->assertSame('lime', $row['tone']);
        $this->assertStringStartsWith('New order #', $row['title']);
        $this->assertTrue($row['unread']);
        $this->assertNotEmpty($row['href']);
        $this->assertNotEmpty($row['time']);
    }

    public function test_index_endpoint_hides_other_admins_notifications(): void
    {
        // Manually attach a notification to the OTHER admin. The
        // bell for $this->admin must NOT see that row.
        $other = $this->inactiveAdmin;
        $other->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => NewOrderAdminNotification::class,
            'data' => ['title' => 'should-not-appear', 'type' => 'orders', 'tone' => 'lime'],
        ]);

        $response = $this->actingAsAdmin()
            ->getJson(route('admin.notifications.index'))
            ->assertOk();

        $this->assertSame(0, (int) $response->json('unread_count'));
        $this->assertSame([], $response->json('notifications'));
    }

    public function test_mark_as_read_moves_one_notification_to_read_and_decrements_unread_count(): void
    {
        [$customer, $variant] = $this->cartReadyForCheckout();
        $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Tester',
                'phone' => '01012345678',
                'governorate' => 'Cairo',
                'area' => 'Nasr City',
                'address' => 'Tahrir St',
                'payment_method' => 'cod',
            ])
            ->assertCreated();

        $notifications = $this->admin->notifications()->get();
        $this->assertCount(1, $notifications);

        $notification = $notifications->first();

        $response = $this->actingAsAdmin()
            ->patchJson(route('admin.notifications.read', ['id' => $notification->id]))
            ->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(0, $this->admin->fresh()->unreadNotifications()->count());
    }

    public function test_mark_as_read_is_idempotent_for_unknown_ids(): void
    {
        $response = $this->actingAsAdmin()
            ->patchJson(route('admin.notifications.read', ['id' => '00000000-0000-0000-0000-000000000000']))
            ->assertOk();

        $response->assertJsonPath('success', true);
    }

    public function test_mark_all_as_read_clears_the_unread_counter_and_writes_a_read_at(): void
    {
        [$customer, $variant] = $this->cartReadyForCheckout();
        $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Tester',
                'phone' => '01012345678',
                'governorate' => 'Cairo',
                'area' => 'Nasr City',
                'address' => 'Tahrir St',
                'payment_method' => 'cod',
            ])
            ->assertCreated();

        // A second customer placing an order fans out a second
        // database-channel notification to the admin.
        $secondCustomer = Customer::factory()->create();
        $secondProduct = Product::factory()->create(['is_archived' => false]);
        $secondVariant = Variant::factory()->create([
            'product_id' => $secondProduct->id,
            'quantity' => 5,
            'price' => 200.00,
        ]);

        CartItem::create([
            'customer_id' => $secondCustomer->id,
            'product_id' => $secondProduct->id,
            'variant_id' => $secondVariant->id,
            'quantity' => 2,
        ]);

        $this->actingAs($secondCustomer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Second Tester',
                'phone' => '01087654321',
                'governorate' => 'Cairo',
                'area' => 'Maadi',
                'address' => 'Street 9',
                'payment_method' => 'cod',
            ])
            ->assertCreated();

        $this->assertGreaterThanOrEqual(2, $this->admin->fresh()->unreadNotifications()->count());

        $response = $this->actingAsAdmin()
            ->postJson(route('admin.notifications.markAllRead'))
            ->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $this->admin->fresh()->unreadNotifications()->count());
        $this->assertNotNull($this->admin->notifications()->first()?->read_at);
    }

    // ─── Service layer contract ───────────────────────────────────

    public function test_admin_notification_service_serializes_payload_into_the_bell_shape(): void
    {
        [$customer, $variant] = $this->cartReadyForCheckout();
        $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Tester',
                'phone' => '01012345678',
                'governorate' => 'Cairo',
                'area' => 'Nasr City',
                'address' => 'Tahrir St',
                'payment_method' => 'cod',
            ])
            ->assertCreated();

        /** @var AdminNotificationService $service */
        $service = app(AdminNotificationService::class);

        $this->assertSame(1, $service->unreadCountFor($this->admin));
        $this->assertTrue($service->markAllAsRead($this->admin) >= 1);
        $this->assertSame(0, $service->unreadCountFor($this->admin));

        $rows = $service->listForAdmin($this->admin);
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('orders', $row['type']);
        $this->assertSame('lime', $row['tone']);
        $this->assertFalse($row['unread']);
    }

    // ─── Routing guards ───────────────────────────────────────────

    public function test_guest_cannot_reach_the_admin_notifications_endpoints(): void
    {
        $this->getJson(route('admin.notifications.index'))->assertRedirect();
        $this->patchJson(
            route('admin.notifications.read', ['id' => '00000000-0000-0000-0000-000000000000']),
        )->assertRedirect();
        $this->postJson(route('admin.notifications.markAllRead'))->assertRedirect();
    }
}
