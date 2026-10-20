<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ShippingSetting;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * HTTP surface of the cart: /cart/summary, /cart/items (store, update,
 * destroy) and /cart/merge, exercised for both a guest and a signed-in
 * customer, plus the automatic merge that happens at login.
 *
 * Guests are a first-class case here: an unauthenticated caller is keyed by
 * session id instead of customer id, so these endpoints must work without an
 * account.
 */
class CartEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::create([
            'name' => 'Ahmed Ali',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        // Also clears the static memo ShippingSetting keeps between tests.
        ShippingSetting::set('free_shipping_threshold', '1000');
        ShippingSetting::set('free_shipping_enabled', '0');
    }

    // ─── Helpers ─────────────────────────────────────────────────

    protected function makeVariant(float $price = 500.0, int $stock = 10): Variant
    {
        return Variant::factory()->create([
            'sku' => strtoupper(Str::random(10)),
            'price' => $price,
            'cost' => $price / 2,
            'discount_price' => null,
            'quantity' => $stock,
        ]);
    }

    protected function seedLine(Customer $customer, Variant $variant, int $quantity = 1): CartItem
    {
        return CartItem::factory()->forCustomer($customer)->forVariant($variant, $quantity)->create();
    }

    /**
     * Replay the session cookie a response set onto every later request in
     * this test.
     *
     * A guest cart is keyed by session id, and Laravel's test client does not
     * echo response cookies back automatically — without this each request
     * would be handed a brand new session id and the visitor's cart would
     * appear to vanish between calls.
     */
    protected function persistSessionCookie(TestResponse $response): void
    {
        $cookie = $response->getCookie(config('session.cookie'));

        $this->assertNotNull($cookie, 'response must set a session cookie');

        $this->withCookie(config('session.cookie'), $cookie->getValue());
    }

    // ─── Guests can use the cart ─────────────────────────────────

    public function test_a_guest_can_add_an_item_without_signing_in(): void
    {
        $variant = $this->makeVariant();

        $this->postJson(route('cart.items.store'), [
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('cart_items', [
            'customer_id' => null,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);
    }

    public function test_a_signed_in_customer_can_add_an_item(): void
    {
        $variant = $this->makeVariant();

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertCreated();

        $this->assertDatabaseHas('cart_items', [
            'customer_id' => $this->customer->id,
            'session_id' => null,
            'variant_id' => $variant->id,
        ]);
    }

    public function test_a_guest_summary_returns_an_empty_cart(): void
    {
        $this->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonPath('item_count', 0)
            ->assertJsonPath('subtotal', 0)
            ->assertJsonPath('free_shipping_threshold', 1000);
    }

    // ─── Adding ──────────────────────────────────────────────────

    public function test_adding_returns_the_refreshed_summary(): void
    {
        $variant = $this->makeVariant(600.0, 10);

        $response = $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 2]);

        $response->assertCreated()
            ->assertJsonPath('summary.item_count', 2)
            ->assertJsonPath('summary.subtotal', 1200)
            ->assertJsonPath('summary.qualifies_for_free_shipping', false);
    }

    public function test_adding_preserves_fractional_cents(): void
    {
        // Money is stored as decimals, so a 0.75-priced variant must not be
        // rounded away on the way through the JSON summary.
        $variant = $this->makeVariant(499.75, 10);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 3])
            ->assertCreated()
            ->assertJsonPath('summary.subtotal', 1499.25);
    }

    public function test_adding_more_than_stock_returns_422(): void
    {
        $variant = $this->makeVariant(500.0, 2);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(0, CartItem::count());
    }

    public function test_adding_requires_a_variant_id(): void
    {
        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_id');
    }

    // ─── Updating ────────────────────────────────────────────────

    public function test_a_customer_can_update_their_own_line_quantity(): void
    {
        $variant = $this->makeVariant(500.0, 10);
        $line = $this->seedLine($this->customer, $variant, 1);

        $this->actingAs($this->customer)
            ->patchJson(route('cart.items.update', $line), ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('summary.item_count', 3);

        $this->assertSame(3, $line->fresh()->quantity);
    }

    public function test_updating_beyond_stock_returns_422(): void
    {
        $variant = $this->makeVariant(500.0, 2);
        $line = $this->seedLine($this->customer, $variant, 1);

        $this->actingAs($this->customer)
            ->patchJson(route('cart.items.update', $line), ['quantity' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    }

    // ─── Removing ────────────────────────────────────────────────

    public function test_a_customer_can_remove_their_own_line(): void
    {
        $variant = $this->makeVariant();
        $line = $this->seedLine($this->customer, $variant, 1);

        $this->actingAs($this->customer)
            ->deleteJson(route('cart.items.destroy', $line))
            ->assertNoContent();

        $this->assertDatabaseMissing('cart_items', ['id' => $line->id]);
    }

    // ─── Ownership is enforced at the HTTP layer ─────────────────

    public function test_updating_someone_elses_line_returns_404(): void
    {
        $other = Customer::create([
            'name' => 'Someone Else',
            'phone' => '01099999999',
            'password' => 'password123',
        ]);
        $line = $this->seedLine($other, $this->makeVariant(), 1);

        $this->actingAs($this->customer)
            ->patchJson(route('cart.items.update', $line), ['quantity' => 3])
            ->assertNotFound();

        $this->assertSame(1, $line->fresh()->quantity);
    }

    public function test_removing_someone_elses_line_returns_404(): void
    {
        $other = Customer::create([
            'name' => 'Someone Else',
            'phone' => '01099999999',
            'password' => 'password123',
        ]);
        $line = $this->seedLine($other, $this->makeVariant(), 1);

        $this->actingAs($this->customer)
            ->deleteJson(route('cart.items.destroy', $line))
            ->assertNotFound();

        $this->assertDatabaseHas('cart_items', ['id' => $line->id]);
    }

    // ─── Summary ─────────────────────────────────────────────────

    public function test_summary_reports_lines_totals_and_threshold_standing(): void
    {
        $cheap = $this->makeVariant(400.0, 10);
        $pricey = $this->makeVariant(950.0, 10);

        $this->seedLine($this->customer, $cheap, 2);
        $this->seedLine($this->customer, $pricey, 1);

        $this->actingAs($this->customer)
            ->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonPath('item_count', 3)
            ->assertJsonPath('subtotal', 1750)
            ->assertJsonPath('free_shipping_threshold', 1000)
            ->assertJsonPath('qualifies_for_free_shipping', false)
            ->assertJsonCount(2, 'items');
    }

    public function test_summary_marks_free_shipping_once_the_threshold_is_met(): void
    {
        ShippingSetting::set('free_shipping_enabled', '1');
        $this->seedLine($this->customer, $this->makeVariant(1200.0, 10), 1);

        $this->actingAs($this->customer)
            ->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonPath('qualifies_for_free_shipping', true)
            ->assertJsonPath('remaining_for_free_shipping', 0);
    }

    // ─── Browser cart sync ───────────────────────────────────────

    public function test_a_guest_cannot_sync_a_cart_to_an_account(): void
    {
        $variant = $this->makeVariant();

        $this->postJson(route('cart.merge'), [
            'items' => [['slug' => $variant->product->slug, 'qty' => 1]],
        ])->assertStatus(401);

        $this->assertSame(0, CartItem::count());
    }

    public function test_a_customer_can_sync_their_browser_cart(): void
    {
        $variant = $this->makeVariant(500.0, 10);

        $this->actingAs($this->customer)
            ->postJson(route('cart.merge'), [
                'items' => [
                    ['slug' => $variant->product->slug, 'color' => $variant->color->hex_code, 'qty' => 2],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('merged', 1)
            ->assertJsonPath('skipped', 0);

        $this->assertDatabaseHas('cart_items', [
            'customer_id' => $this->customer->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);
    }

    public function test_syncing_reports_lines_it_could_not_resolve(): void
    {
        $this->actingAs($this->customer)
            ->postJson(route('cart.merge'), [
                'items' => [['slug' => 'no-such-product', 'qty' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('merged', 0)
            ->assertJsonPath('skipped', 1);
    }

    // ─── Login merges the guest cart ─────────────────────────────

    public function test_signing_in_absorbs_the_guest_session_cart(): void
    {
        $variant = $this->makeVariant(500.0, 10);

        // Fill a cart while still a guest, over the session the login
        // request will carry.
        $this->persistSessionCookie(
            $this->post(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 2])
                ->assertCreated(),
        );

        $this->assertDatabaseHas('cart_items', ['customer_id' => null, 'quantity' => 2]);

        $this->post(route('login'), [
            'identifier' => '01012345678',
            'password' => 'password123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($this->customer);

        // The guest line now belongs to the account.
        $this->assertDatabaseHas('cart_items', [
            'customer_id' => $this->customer->id,
            'session_id' => null,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);
        $this->assertSame(0, CartItem::whereNull('customer_id')->count());
    }

    public function test_signing_in_keeps_the_accounts_own_cart(): void
    {
        $variant = $this->makeVariant(500.0, 10);
        $this->seedLine($this->customer, $variant, 1);

        $this->post(route('login'), [
            'identifier' => '01012345678',
            'password' => 'password123',
        ])->assertRedirect();

        $this->assertDatabaseHas('cart_items', [
            'customer_id' => $this->customer->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);
    }

    public function test_a_failed_login_leaves_the_guest_cart_untouched(): void
    {
        $variant = $this->makeVariant(500.0, 10);

        $this->persistSessionCookie(
            $this->post(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 2])
                ->assertCreated(),
        );

        $this->from('/login')->post(route('login'), [
            'identifier' => '01012345678',
            'password' => 'wrong-password',
        ])->assertRedirect();

        $this->assertGuest();

        // Still a guest cart — nothing was moved to an account.
        $this->assertDatabaseHas('cart_items', ['customer_id' => null, 'quantity' => 2]);
    }
}
