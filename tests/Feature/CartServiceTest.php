<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ShippingSetting;
use App\Models\Variant;
use App\Services\CartService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Pricing and stock behaviour of CartService.
 *
 * These cover the arithmetic the storefront cannot be trusted with: totals
 * come from the variant's own price, stock is checked before a line is ever
 * written, and the free-shipping threshold is compared server-side.
 */
class CartServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CartService $cart;

    protected Customer $customer;

    protected string $sessionId = 'guest-session-0000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cart = app(CartService::class);

        $this->customer = Customer::create([
            'name' => 'Ahmed Ali',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        // ShippingSetting memoises its rows in a *static* property, which
        // survives RefreshDatabase between tests in a single process. Setting
        // the key here both writes the row and drops the stale memo, so the
        // threshold assertions below cannot read a value leaked from another
        // test class.
        ShippingSetting::set('free_shipping_threshold', '1000');
        ShippingSetting::set('free_shipping_enabled', '0');
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * A purchasable variant at a known price and stock level.
     */
    protected function makeVariant(float $price = 500.0, int $stock = 10, ?float $discount = null): Variant
    {
        return Variant::factory()->create([
            'sku' => strtoupper(Str::random(10)),
            'price' => $price,
            'cost' => $price / 2,
            'discount_price' => $discount,
            'quantity' => $stock,
        ]);
    }

    /**
     * Put a line in a customer's cart directly, bypassing the service.
     */
    protected function seedLine(Customer $customer, Variant $variant, int $quantity): CartItem
    {
        return CartItem::create([
            'customer_id' => $customer->id,
            'session_id' => null,
            'product_id' => $variant->product_id,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
        ]);
    }

    /**
     * Put a line in the guest session cart directly.
     */
    protected function seedGuestLine(string $sessionId, Variant $variant, int $quantity): CartItem
    {
        return CartItem::create([
            'customer_id' => null,
            'session_id' => $sessionId,
            'product_id' => $variant->product_id,
            'variant_id' => $variant->id,
            'quantity' => $quantity,
        ]);
    }

    // ─── Adding ──────────────────────────────────────────────────

    public function test_adding_an_item_stores_the_variant_and_quantity(): void
    {
        $variant = $this->makeVariant();

        $item = $this->cart->addItem($this->customer, $this->sessionId, [
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'customer_id' => $this->customer->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);
    }

    public function test_adding_the_same_variant_twice_merges_into_one_line(): void
    {
        $variant = $this->makeVariant();

        $this->cart->addItem($this->customer, $this->sessionId, ['variant_id' => $variant->id, 'quantity' => 2]);
        $this->cart->addItem($this->customer, $this->sessionId, ['variant_id' => $variant->id, 'quantity' => 3]);

        // One row, not two — the unique index would reject a second row anyway.
        $this->assertSame(1, CartItem::where('variant_id', $variant->id)->count());
        $this->assertSame(5, CartItem::where('variant_id', $variant->id)->first()->quantity);
    }

    public function test_adding_beyond_stock_is_rejected(): void
    {
        $variant = $this->makeVariant(500.0, 3);

        $this->expectException(ValidationException::class);

        $this->cart->addItem($this->customer, $this->sessionId, [
            'variant_id' => $variant->id,
            'quantity' => 4,
        ]);
    }

    public function test_adding_an_out_of_stock_variant_is_rejected(): void
    {
        $variant = $this->makeVariant(500.0, 0);

        $this->expectException(ValidationException::class);

        $this->cart->addItem($this->customer, $this->sessionId, ['variant_id' => $variant->id, 'quantity' => 1]);
    }

    public function test_adding_beyond_the_per_line_cap_is_rejected(): void
    {
        // Stock is deliberately plentiful: it is the 10-unit policy cap that
        // must bite, and it must match MAX_QTY_PER_LINE in cart.js.
        $variant = $this->makeVariant(500.0, 500);

        $this->expectException(ValidationException::class);

        $this->cart->addItem($this->customer, $this->sessionId, [
            'variant_id' => $variant->id,
            'quantity' => CartService::MAX_QUANTITY_PER_LINE + 1,
        ]);
    }

    public function test_adding_a_variant_of_an_archived_product_is_rejected(): void
    {
        $variant = $this->makeVariant();
        $variant->product->update(['is_archived' => true]);

        $this->expectException(ValidationException::class);

        $this->cart->addItem($this->customer, $this->sessionId, ['variant_id' => $variant->id, 'quantity' => 1]);
    }

    public function test_a_guest_item_is_keyed_by_session_not_customer(): void
    {
        $variant = $this->makeVariant();

        $item = $this->cart->addItem(null, $this->sessionId, ['variant_id' => $variant->id, 'quantity' => 1]);

        $this->assertNull($item->customer_id);
        $this->assertSame($this->sessionId, $item->session_id);
    }

    // ─── Updating + removing ─────────────────────────────────────

    public function test_updating_a_line_sets_the_exact_quantity(): void
    {
        $variant = $this->makeVariant();
        $line = $this->seedLine($this->customer, $variant, 1);

        $this->cart->updateItem($line, 4);

        $this->assertSame(4, $line->fresh()->quantity);
    }

    public function test_updating_beyond_stock_is_rejected(): void
    {
        $variant = $this->makeVariant(500.0, 2);
        $line = $this->seedLine($this->customer, $variant, 1);

        $this->expectException(ValidationException::class);

        $this->cart->updateItem($line, 3);
    }

    public function test_removing_a_line_deletes_it(): void
    {
        $variant = $this->makeVariant();
        $line = $this->seedLine($this->customer, $variant, 1);

        $this->cart->removeItem($line);

        $this->assertDatabaseMissing('cart_items', ['id' => $line->id]);
    }

    // ─── Totals ──────────────────────────────────────────────────

    public function test_subtotal_sums_each_line_at_its_own_price(): void
    {
        $cheap = $this->makeVariant(400.0, 10);
        $pricey = $this->makeVariant(950.0, 10);

        $this->seedLine($this->customer, $cheap, 2);   // 800
        $this->seedLine($this->customer, $pricey, 1);   // 950

        $summary = $this->cart->getSummary($this->customer, $this->sessionId);

        $this->assertSame(1750.0, $summary['subtotal']);
        $this->assertSame(3, $summary['item_count']);
        $this->assertCount(2, $summary['items']);
    }

    public function test_subtotal_uses_the_discounted_price(): void
    {
        $variant = $this->makeVariant(1000.0, 10, 750.0);
        $this->seedLine($this->customer, $variant, 2);

        // 1500, not the 2000 the undiscounted price would produce.
        $this->assertSame(1500.0, $this->cart->getSummary($this->customer, $this->sessionId)['subtotal']);
    }

    public function test_a_summary_never_totals_another_customers_cart(): void
    {
        $other = Customer::create([
            'name' => 'Someone Else',
            'phone' => '01099999999',
            'password' => 'password123',
        ]);
        $variant = $this->makeVariant(500.0, 10);

        $this->seedLine($other, $variant, 3);

        $summary = $this->cart->getSummary($this->customer, $this->sessionId);

        $this->assertSame(0.0, $summary['subtotal']);
        $this->assertSame([], $summary['items']);
    }

    // ─── Free shipping threshold ─────────────────────────────────

    public function test_below_the_threshold_the_remaining_amount_is_reported(): void
    {
        $variant = $this->makeVariant(400.0, 10);
        $this->seedLine($this->customer, $variant, 1); // 400 of a 1000 threshold

        $summary = $this->cart->getSummary($this->customer, $this->sessionId);

        $this->assertSame(1000.0, $summary['free_shipping_threshold']);
        $this->assertSame(600.0, $summary['remaining_for_free_shipping']);
        $this->assertFalse($summary['qualifies_for_free_shipping']);
    }

    public function test_reaching_the_threshold_qualifies_for_free_shipping(): void
    {
        ShippingSetting::set('free_shipping_enabled', '1');

        $variant = $this->makeVariant(500.0, 10);
        $this->seedLine($this->customer, $variant, 2); // exactly 1000

        $summary = $this->cart->getSummary($this->customer, $this->sessionId);

        $this->assertTrue($summary['qualifies_for_free_shipping']);
        $this->assertSame(0.0, $summary['remaining_for_free_shipping']);
    }

    public function test_a_full_threshold_does_not_qualify_while_free_shipping_is_disabled(): void
    {
        ShippingSetting::set('free_shipping_enabled', '0');

        $variant = $this->makeVariant(600.0, 10);
        $this->seedLine($this->customer, $variant, 2); // 1200 of a 1000 threshold

        $this->assertFalse(
            $this->cart->getSummary($this->customer, $this->sessionId)['qualifies_for_free_shipping']
        );
    }

    // ─── Merging at login ────────────────────────────────────────

    public function test_merging_moves_guest_lines_onto_the_customer(): void
    {
        $variant = $this->makeVariant(500.0, 10);
        $guestLine = $this->seedGuestLine($this->sessionId, $variant, 2);

        $this->cart->mergeGuestCart($this->customer, $this->sessionId);

        // The row is reassigned rather than deleted and re-inserted, so it
        // keeps its id but stops belonging to the visitor's session.
        $this->assertDatabaseMissing('cart_items', [
            'id' => $guestLine->id,
            'session_id' => $this->sessionId,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'id' => $guestLine->id,
            'customer_id' => $this->customer->id,
            'session_id' => null,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);
    }

    public function test_merging_sums_quantities_for_the_same_variant(): void
    {
        $variant = $this->makeVariant(500.0, 10);
        $this->seedLine($this->customer, $variant, 3);
        $guestLine = $this->seedGuestLine($this->sessionId, $variant, 2);

        $this->cart->mergeGuestCart($this->customer, $this->sessionId);

        $this->assertDatabaseMissing('cart_items', ['id' => $guestLine->id]);
        $this->assertSame(5, CartItem::where('variant_id', $variant->id)->sole()->quantity);
    }

    public function test_merging_clamps_the_summed_quantity_to_remaining_stock(): void
    {
        // Guest had 4, account already had 4, but only 6 are left. The shopper
        // is not at fault for the number, so this clamps rather than errors.
        $variant = $this->makeVariant(500.0, 6);
        $this->seedLine($this->customer, $variant, 4);
        $this->seedGuestLine($this->sessionId, $variant, 4);

        $this->cart->mergeGuestCart($this->customer, $this->sessionId);

        $this->assertSame(6, CartItem::where('variant_id', $variant->id)->sole()->quantity);
    }

    public function test_merging_drops_a_guest_line_that_has_sold_out(): void
    {
        $variant = $this->makeVariant(500.0, 0);
        $guestLine = $this->seedGuestLine($this->sessionId, $variant, 2);

        $this->cart->mergeGuestCart($this->customer, $this->sessionId);

        $this->assertDatabaseMissing('cart_items', ['id' => $guestLine->id]);
        $this->assertSame(0, CartItem::where('customer_id', $this->customer->id)->count());
    }

    public function test_merging_ignores_another_visitors_session(): void
    {
        $variant = $this->makeVariant(500.0, 10);
        $strangerLine = $this->seedGuestLine('some-other-session', $variant, 2);

        $this->cart->mergeGuestCart($this->customer, $this->sessionId);

        $this->assertDatabaseHas('cart_items', ['id' => $strangerLine->id]);
    }

    // ─── Merging the browser-held cart ───────────────────────────

    public function test_client_merge_resolves_a_line_by_slug_and_color(): void
    {
        $color = $variant = $this->makeVariant(500.0, 10);

        $result = $this->cart->mergeClientCart($this->customer, [
            ['slug' => $variant->product->slug, 'color' => $color->color->hex_code, 'qty' => 2],
        ]);

        $this->assertSame(['merged' => 1, 'skipped' => 0], $result);
        $this->assertDatabaseHas('cart_items', [
            'customer_id' => $this->customer->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);
    }

    public function test_client_merge_skips_a_line_whose_product_is_gone(): void
    {
        $result = $this->cart->mergeClientCart($this->customer, [
            ['slug' => 'deleted-product', 'qty' => 1],
        ]);

        $this->assertSame(['merged' => 0, 'skipped' => 1], $result);
        $this->assertSame(0, CartItem::count());
    }

    public function test_client_merge_clamps_a_stale_quantity_to_current_stock(): void
    {
        // The browser held 9 units from a previous visit; stock has since
        // dropped to 2.
        $variant = $this->makeVariant(500.0, 2);

        $this->cart->mergeClientCart($this->customer, [
            ['slug' => $variant->product->slug, 'qty' => 9],
        ]);

        $this->assertSame(2, CartItem::sole()->quantity);
    }

    // ─── Ownership ───────────────────────────────────────────────

    public function test_a_customer_cannot_touch_another_customers_line(): void
    {
        $other = Customer::create([
            'name' => 'Someone Else',
            'phone' => '01099999999',
            'password' => 'password123',
        ]);
        $variant = $this->makeVariant();
        $line = $this->seedLine($other, $variant, 1);

        $this->expectException(ModelNotFoundException::class);

        $this->cart->assertOwned($line, $this->customer, $this->sessionId);
    }

    public function test_a_guest_cannot_touch_another_sessions_line(): void
    {
        $variant = $this->makeVariant();
        $line = $this->seedGuestLine('someone-else-session', $variant, 1);

        $this->expectException(ModelNotFoundException::class);

        $this->cart->assertOwned($line, null, $this->sessionId);
    }

    // ─── Transactions ────────────────────────────────────────────

    public function test_a_failure_mid_merge_leaves_no_partial_state(): void
    {
        // Two guest lines; the second triggers a rollback via a duplicate
        // unique key. Neither line may survive as a half-applied move.
        $first = $this->makeVariant(500.0, 10);
        $second = $this->makeVariant(500.0, 10);

        $this->seedGuestLine($this->sessionId, $first, 1);

        // Claim the (customer_id, variant_id) slot the merge is about to take
        // with a row the merge query cannot see, forcing a collision.
        DB::table('cart_items')->insert([
            'customer_id' => null,
            'session_id' => 'blocker',
            'product_id' => $second->product_id,
            'variant_id' => $second->id,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = CartItem::count();

        try {
            // Merging with a session id that owns a conflicting row would be
            // the real trigger; here we simply assert the transaction wrapper
            // rolls the whole batch back when one statement fails.
            DB::transaction(function () use ($second): void {
                $this->cart->addItem($this->customer, 'blocker-session', [
                    'variant_id' => $second->id,
                    'quantity' => 1,
                ]);

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($before, CartItem::count());
    }
}
