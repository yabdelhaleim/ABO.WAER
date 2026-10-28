<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AdminActivity;
use App\Models\CancellationReason;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\Variant;
use App\Services\Admin\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The settings screen, end to end.
 *
 * The screen used to draw a static array and save nothing. These tests
 * pin the three things that make it real: the values on the page come
 * from the database, a save writes to it, and the settings that have a
 * consumer in the shop actually reach it.
 */
class SettingsTest extends TestCase
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

        // StoreSetting memoises its rows in a *static* property, which
        // survives RefreshDatabase between tests in a single process.
        // Flushing it here keeps a value another test stored from
        // leaking into this one.
        StoreSetting::flush();
    }

    protected function tearDown(): void
    {
        StoreSetting::flush();

        parent::tearDown();
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin');
    }

    /**
     * The settings the screen sends when nothing was changed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
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
                'returnRequest' => true,
                'shipmentFailed' => false,
                'couponExpiring' => false,
                'browserSound' => true,
            ],
        ], $overrides);
    }

    /**
     * The settings the server hands the screen.
     *
     * @return array<string, mixed>
     */
    private function payloadFrom(string $html): array
    {
        preg_match('/<script type="application\/json" data-abo-admin-settings-payload>(.+?)<\/script>/s', $html, $m);

        $this->assertArrayHasKey(1, $m, 'the settings screen must publish its payload');

        return json_decode($m[1], true);
    }

    // ─── The screen ───────────────────────────────────────────────

    public function test_the_settings_screen_shows_the_stored_settings(): void
    {
        StoreSetting::set('store.name', 'ABO Streetwear');
        StoreSetting::set('orders.prefix', 'ABOX-');

        $response = $this->actingAsAdmin()->get(route('admin.settings.index'));

        $response->assertOk()->assertViewIs('admin.settings.index');

        $payload = $this->payloadFrom($response->getContent() ?: '');

        $this->assertSame('ABO Streetwear', $payload['store']['name']);
        $this->assertSame('ABOX-', $payload['orders']['prefix']);
    }

    public function test_the_screen_invents_no_contact_details(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.settings.index'));

        $payload = $this->payloadFrom($response->getContent() ?: '');

        // A store that never typed its phone has no phone — the old
        // screen used to show one it had made up.
        $this->assertSame('', $payload['store']['phone']);
        $this->assertSame('', $payload['store']['email']);
        $this->assertSame('', $payload['store']['address']);
        $this->assertSame('', $payload['store']['social']['instagram']);
    }

    public function test_the_screen_shows_the_next_order_number_the_shop_would_hand_out(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.settings.index'));

        $payload = $this->payloadFrom($response->getContent() ?: '');

        $this->assertSame('ABO-'.date('Y').'-1001', $payload['orders']['nextNumber']);
    }

    // ─── Saving ───────────────────────────────────────────────────

    public function test_saving_the_settings_writes_them_to_the_database(): void
    {
        $this->actingAsAdmin()
            ->putJson(route('admin.settings.update'), $this->body([
                'store' => [
                    'name' => 'ABO Streetwear',
                    'phone' => '+20 100 000 0000',
                    'email' => 'hello@example.com',
                    'social' => ['instagram' => 'instagram.com/abo'],
                ],
                'orders' => ['minimum' => 750, 'prefix' => 'ABOX-'],
                'inventory' => ['lowStockThreshold' => 3],
            ]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('ABO Streetwear', StoreSetting::get('store.name'));
        $this->assertSame('+20 100 000 0000', StoreSetting::get('store.phone'));
        $this->assertSame('hello@example.com', StoreSetting::get('store.email'));
        $this->assertSame('instagram.com/abo', StoreSetting::get('store.social.instagram'));
        $this->assertSame(750, StoreSetting::get('orders.minimum'));
        $this->assertSame('ABOX-', StoreSetting::get('orders.prefix'));
        $this->assertSame(3, StoreSetting::get('inventory.lowStockThreshold'));
    }

    public function test_the_answer_carries_the_settings_as_they_are_now(): void
    {
        $response = $this->actingAsAdmin()
            ->putJson(route('admin.settings.update'), $this->body([
                'store' => ['name' => 'ABO Streetwear'],
            ]))
            ->assertOk();

        $this->assertSame('ABO Streetwear', $response->json('settings.store.name'));
        $this->assertSame('ABO-'.date('Y').'-1001', $response->json('settings.orders.nextNumber'));
    }

    public function test_the_settings_reject_a_name_that_is_not_a_name(): void
    {
        $this->actingAsAdmin()
            ->putJson(route('admin.settings.update'), $this->body([
                'store' => ['name' => ''],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store.name');

        $this->assertNull(StoreSetting::get('store.name'));
    }

    public function test_the_settings_reject_a_paper_size_that_is_not_a_paper_size(): void
    {
        $this->actingAsAdmin()
            ->putJson(route('admin.settings.update'), $this->body([
                'receipts' => ['paper' => 'a3'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipts.paper');
    }

    // ─── The cancellation reasons ─────────────────────────────────

    public function test_the_cancellation_reasons_are_the_rows_in_the_database(): void
    {
        CancellationReason::create([
            'reason' => 'Changed my mind',
            'reason_ar' => 'غيّرت رأيي',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.settings.index'));

        $payload = $this->payloadFrom($response->getContent() ?: '');

        $this->assertCount(1, $payload['reasons']);
        $this->assertSame('Changed my mind', $payload['reasons'][0]['en']);
        $this->assertSame('غيّرت رأيي', $payload['reasons'][0]['ar']);
        $this->assertTrue($payload['reasons'][0]['active']);
    }

    public function test_saving_the_reasons_creates_updates_and_removes_the_rows(): void
    {
        $kept = CancellationReason::create(['reason' => 'Changed my mind', 'sort_order' => 0]);
        $removed = CancellationReason::create(['reason' => 'Wrong size', 'sort_order' => 1]);

        $this->actingAsAdmin()
            ->putJson(route('admin.settings.update'), $this->body([
                'reasons' => [
                    ['id' => $kept->id, 'en' => 'Changed my mind', 'ar' => 'غيّرت رأيي', 'active' => true],
                    ['id' => 'new-1', 'en' => 'Found a better price', 'ar' => 'وجدت سعراً أفضل', 'active' => false],
                ],
            ]))
            ->assertOk();

        $this->assertDatabaseHas('cancellation_reasons', [
            'id' => $kept->id,
            'reason' => 'Changed my mind',
            'reason_ar' => 'غيّرت رأيي',
        ]);

        $this->assertDatabaseHas('cancellation_reasons', [
            'reason' => 'Found a better price',
            'is_active' => false,
        ]);

        $this->assertDatabaseMissing('cancellation_reasons', ['id' => $removed->id]);
    }

    public function test_a_reason_the_admin_removed_is_gone_from_the_list(): void
    {
        $reason = CancellationReason::create(['reason' => 'Wrong size', 'sort_order' => 0]);

        $response = $this->actingAsAdmin()
            ->putJson(route('admin.settings.update'), $this->body(['reasons' => []]))
            ->assertOk();

        $this->assertSame([], $response->json('settings.reasons'));
        $this->assertDatabaseMissing('cancellation_reasons', ['id' => $reason->id]);
    }

    // ─── The settings that reach the shop ─────────────────────────

    public function test_the_order_prefix_reaches_the_order_numbers(): void
    {
        StoreSetting::set('orders.prefix', 'ABOX-');

        $this->assertStringStartsWith('ABOX-', Order::generateOrderNumber());
    }

    public function test_the_minimum_order_value_refuses_a_smaller_order(): void
    {
        StoreSetting::set('orders.minimum', 1000);

        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 100.00,
        ]);

        $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), [
                'name' => 'Omar Khalid',
                'phone' => '01012345678',
                'governorate' => 'Cairo',
                'area' => 'Nasr City',
                'address' => 'Abbas El Akkad St',
                'payment_method' => 'cod',
                'cart_json' => json_encode([
                    ['variant_id' => $variant->id, 'quantity' => 1],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cart');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_guest_checkout_off_needs_a_signed_in_customer(): void
    {
        StoreSetting::set('orders.guestCheckout', false);

        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 10,
            'price' => 100.00,
        ]);

        $this->postJson(route('checkout.process'), [
            'name' => 'Omar Khalid',
            'phone' => '01012345678',
            'governorate' => 'Cairo',
            'area' => 'Nasr City',
            'address' => 'Abbas El Akkad St',
            'payment_method' => 'cod',
            'cart_json' => json_encode([
                ['variant_id' => $variant->id, 'quantity' => 1],
            ]),
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_maintenance_mode_closes_the_storefront_and_leaves_the_admin_open(): void
    {
        StoreSetting::set('store.maintenance', true);

        $this->get(route('home'))->assertStatus(503);
        $this->get(route('shop'))->assertStatus(503);

        // The back office keeps working — the person who turned the
        // switch on has to be able to turn it off again.
        $this->actingAsAdmin()->get(route('admin.settings.index'))->assertOk();
    }

    public function test_the_storefront_is_open_when_maintenance_mode_is_off(): void
    {
        $this->get(route('home'))->assertOk();
    }

    public function test_the_low_stock_threshold_is_the_one_the_admin_set(): void
    {
        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $dashboard = app(DashboardService::class);

        $this->assertSame(5, DashboardService::lowStockThreshold());
        $this->assertCount(1, $dashboard->getLowStockItems());

        StoreSetting::set('inventory.lowStockThreshold', 1);
        StoreSetting::flush();

        $this->assertSame(1, DashboardService::lowStockThreshold());
        $this->assertCount(0, $dashboard->getLowStockItems());
    }

    public function test_hiding_out_of_stock_hides_the_sold_out_products(): void
    {
        $product = Product::factory()->create([
            'name' => 'Sold Out Hoodie',
            'is_archived' => false,
        ]);
        Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 0,
        ]);

        $this->get(route('shop'))->assertOk()->assertSee('Sold Out Hoodie');

        StoreSetting::set('inventory.hideOutOfStock', true);
        StoreSetting::flush();

        $this->get(route('shop'))->assertOk()->assertDontSee('Sold Out Hoodie');
    }

    // ─── The branding ─────────────────────────────────────────────

    public function test_the_branding_upload_stores_the_logo(): void
    {
        Storage::fake('public');

        $this->actingAsAdmin()
            ->post(route('admin.settings.branding'), [
                'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $stored = StoreSetting::get('store.logo');

        $this->assertNotNull($stored);
        Storage::disk('public')->assertExists($stored);
    }

    // ─── Who may do this ──────────────────────────────────────────

    public function test_a_manager_may_look_at_the_settings_but_not_change_them(): void
    {
        $manager = Admin::create([
            'name' => 'Store Manager',
            'email' => 'manager@store.com',
            'password' => 'password',
            'is_active' => true,
            'role' => AdminRole::Manager->value,
        ]);

        $this->actingAs($manager, 'admin')
            ->get(route('admin.settings.index'))
            ->assertOk();

        $this->actingAs($manager, 'admin')
            ->putJson(route('admin.settings.update'), $this->body([
                'store' => ['name' => 'Not allowed'],
            ]))
            ->assertForbidden();

        $this->assertNull(StoreSetting::get('store.name'));
    }

    public function test_a_guest_cannot_reach_the_settings(): void
    {
        $this->get(route('admin.settings.index'))->assertRedirect(route('admin.login'));

        $this->putJson(route('admin.settings.update'), $this->body())
            ->assertRedirect(route('admin.login'));
    }

    // ─── Restoring the defaults ───────────────────────────────────

    public function test_restore_defaults_puts_every_setting_back(): void
    {
        StoreSetting::set('store.name', 'ABO Streetwear');
        StoreSetting::set('orders.prefix', 'ABOX-');
        CancellationReason::create(['reason' => 'Wrong size']);

        $response = $this->actingAsAdmin()
            ->postJson(route('admin.settings.reset'))
            ->assertOk();

        $this->assertSame('ABO', $response->json('settings.store.name'));
        $this->assertSame('ABO-', $response->json('settings.orders.prefix'));

        $this->assertNull(StoreSetting::get('store.name'));
        $this->assertSame(0, CancellationReason::query()->count());
    }

    // ─── The trail ────────────────────────────────────────────────

    public function test_the_activity_trail_records_the_save(): void
    {
        $this->actingAsAdmin()
            ->putJson(route('admin.settings.update'), $this->body([
                'store' => ['name' => 'ABO Streetwear'],
            ]))
            ->assertOk();

        $this->assertDatabaseHas('admin_activity', [
            'admin_id' => $this->admin->id,
            'action' => 'settings.updated',
        ]);

        $this->actingAsAdmin()
            ->postJson(route('admin.settings.reset'))
            ->assertOk();

        $this->assertDatabaseHas('admin_activity', [
            'admin_id' => $this->admin->id,
            'action' => 'settings.reset',
        ]);

        $this->assertSame(2, AdminActivity::query()->count());
    }
}
