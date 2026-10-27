<?php

namespace Tests\Feature\Store;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ShippingSetting;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The server half of the server-backed cart.
 *
 * The browser client (resources/js/cart.js and resources/js/product.js)
 * resolves a shopper's colour × size choice to a variant id and sends that
 * id to the cart API. These tests pin the contract the client leans on:
 *
 *  - the id is honoured exactly — the line is the variant that was chosen,
 *    not the product's cheapest one;
 *  - the price comes off that same variant, discount and cents included;
 *  - a variant that cannot be sold is refused, so the client can report a
 *    real reason instead of a false success;
 *  - a variant whose colour has no product_colors row is still sellable,
 *    because the variant — not the pivot — is the source of truth.
 */
class CartServerBindingTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected Color $onyx;

    protected Color $lime;

    protected Size $sizeM;

    protected Size $sizeL;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'is_active' => true,
            'is_visible' => true,
        ]);

        $this->onyx = Color::factory()->create([
            'name' => 'Onyx',
            'hex_code' => '#0D0F11',
            'is_active' => true,
        ]);
        $this->lime = Color::factory()->create([
            'name' => 'Lime',
            'hex_code' => '#C6FF00',
            'is_active' => true,
        ]);

        $this->sizeM = Size::factory()->create([
            'name' => 'M',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $this->sizeL = Size::factory()->create([
            'name' => 'L',
            'sort_order' => 3,
            'is_active' => true,
        ]);

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

    protected function makeProduct(string $slug = 'drop-02-hoodie', bool $archived = false): Product
    {
        return Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => $slug,
            'category_id' => $this->category->id,
            'is_archived' => $archived,
        ]);
    }

    protected function makeVariant(
        Product $product,
        Color $color,
        Size $size,
        float $price = 1490.0,
        int $stock = 8,
        ?float $discount = null,
    ): Variant {
        return Variant::create([
            'product_id' => $product->id,
            'color_id' => $color->id,
            'size_id' => $size->id,
            'sku' => strtoupper(Str::random(10)),
            'price' => $price,
            'cost' => $price / 2,
            'discount_price' => $discount,
            'quantity' => $stock,
        ]);
    }

    /**
     * Replay the session cookie a response set onto every later request in
     * this test — a guest cart is keyed by session id, and Laravel's test
     * client does not echo response cookies back by itself.
     *
     * withCredentials() is what makes a JSON request carry the cookie at
     * all. It is the test client's stand-in for the browser's
     * `credentials: 'same-origin'`, which is how the cart client sends
     * every request.
     */
    protected function persistSessionCookie(TestResponse $response): void
    {
        $cookie = $response->getCookie(config('session.cookie'));

        $this->assertNotNull($cookie, 'response must set a session cookie');

        $this->withCookie(config('session.cookie'), $cookie->getValue());
        $this->withCredentials();
    }

    // ─── The chosen variant is the one that is added ────────────

    public function test_adding_the_variant_the_client_resolved_stores_that_exact_row(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, $this->lime, $this->sizeL, 1490.0, 8);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), [
                'variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('cart_items', [
            'customer_id' => $this->customer->id,
            'variant_id' => $variant->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        // And the summary hands the client back the very same line, with
        // the colour and size the shopper picked. Whole money values
        // arrive as integers — json_encode() drops the trailing ".0" —
        // so that is how they are written here too.
        $this->actingAs($this->customer)
            ->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonPath('items.0.variant_id', $variant->id)
            ->assertJsonPath('items.0.color', 'Lime')
            ->assertJsonPath('items.0.color_hex', '#C6FF00')
            ->assertJsonPath('items.0.size', 'L')
            ->assertJsonPath('items.0.price', 1490)
            ->assertJsonPath('items.0.quantity', 1)
            ->assertJsonPath('items.0.line_total', 1490)
            ->assertJsonPath('items.0.available_stock', 8)
            ->assertJsonPath('items.0.is_available', true);
    }

    public function test_the_summary_carries_the_fields_the_client_paints_the_cart_with(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, $this->onyx, $this->sizeM);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertCreated();

        $item = $this->actingAs($this->customer)
            ->getJson(route('cart.summary'))
            ->assertOk()
            ->json('items.0');

        // The client needs an id for the line (to PATCH / DELETE it), the
        // variant it stands for, the product behind it, and a price it
        // never has to compute itself.
        foreach (['id', 'product_id', 'variant_id', 'slug', 'name', 'price', 'quantity', 'line_total', 'color', 'color_hex', 'size', 'available_stock', 'is_available'] as $key) {
            $this->assertArrayHasKey($key, $item, "summary item must carry '$key' for the client");
        }

        $this->assertSame($product->slug, $item['slug']);
        $this->assertSame($variant->id, $item['variant_id']);
    }

    // ─── Variants that cannot be sold are refused ───────────────

    public function test_adding_a_variant_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => 999999, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_id');

        // Nothing was created — the client must be able to say "no" and
        // keep the cart as it was.
        $this->assertSame(0, CartItem::count());
    }

    public function test_adding_a_variant_of_an_archived_product_is_refused(): void
    {
        $product = $this->makeProduct('archived-drop', archived: true);
        $variant = $this->makeVariant($product, $this->onyx, $this->sizeM);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_id');

        $this->assertSame(0, CartItem::count());
    }

    public function test_adding_a_sold_out_variant_is_refused_and_names_the_option(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, $this->onyx, $this->sizeM, 1490.0, 0);

        $response = $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_id');

        // The client shows this message verbatim, so it has to be the
        // field-level one — the generic 422 message would not help a
        // shopper.
        $this->assertStringContainsString('out of stock', $response->json('errors.variant_id.0'));
        $this->assertSame(0, CartItem::count());
    }

    public function test_a_quantity_beyond_the_stock_is_refused_and_the_line_is_left_alone(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, $this->onyx, $this->sizeM, 1490.0, 2);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertCreated();

        $this->actingAs($this->customer)
            ->patchJson(route('cart.items.update', CartItem::first()), ['quantity' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        // The refused update must not have moved the line.
        $this->assertSame(1, CartItem::first()->quantity);
    }

    // ─── Prices come off the variant, not the product ───────────

    public function test_two_variants_of_one_product_keep_their_own_prices(): void
    {
        $product = $this->makeProduct();
        $cheap = $this->makeVariant($product, $this->onyx, $this->sizeM, 1200.0, 5);
        $pricey = $this->makeVariant($product, $this->lime, $this->sizeL, 1890.0, 5);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $cheap->id, 'quantity' => 2])
            ->assertCreated();

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $pricey->id, 'quantity' => 1])
            ->assertCreated();

        $summary = $this->actingAs($this->customer)
            ->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->json();

        // Each line is priced off the variant the shopper chose — never
        // off the product's cheapest variant.
        $prices = collect($summary['items'])->pluck('price', 'variant_id');
        $this->assertSame(1200.0, (float) $prices[$cheap->id]);
        $this->assertSame(1890.0, (float) $prices[$pricey->id]);

        // 1200 × 2 + 1890 × 1
        $this->assertSame(4290.0, (float) $summary['subtotal']);
    }

    public function test_a_discounted_variant_is_priced_at_the_discount(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, $this->onyx, $this->sizeM, 1890.0, 8, discount: 1490.0);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 2])
            ->assertCreated()
            ->assertJsonPath('summary.items.0.price', 1490)
            ->assertJsonPath('summary.items.0.line_total', 2980)
            ->assertJsonPath('summary.subtotal', 2980);
    }

    public function test_a_fractional_price_survives_the_whole_round_trip(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, $this->onyx, $this->sizeM, 499.75, 8);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 3])
            ->assertCreated()
            ->assertJsonPath('summary.items.0.price', 499.75)
            ->assertJsonPath('summary.items.0.line_total', 1499.25)
            ->assertJsonPath('summary.subtotal', 1499.25);
    }

    public function test_the_same_variant_added_twice_becomes_one_line(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, $this->onyx, $this->sizeM, 1490.0, 8);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertCreated();

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 2])
            ->assertCreated();

        // One variant is one line — the second add tops the line up.
        $this->assertSame(1, CartItem::count());
        $this->assertSame(3, CartItem::first()->quantity);
    }

    // ─── A variant beats the product_colors pivot ────────────────

    public function test_a_variant_whose_colour_has_no_pivot_row_still_sells_and_still_reports_its_colour(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, $this->lime, $this->sizeM, 1490.0, 8);

        // Deliberately no product_colors row and no colours() sync: the
        // pivot is a catalogue convenience, the variant is the truth.
        $this->assertDatabaseMissing('product_colors', [
            'product_id' => $product->id,
            'color_id' => $this->lime->id,
        ]);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertCreated()
            ->assertJsonPath('summary.items.0.variant_id', $variant->id)
            // The colour still comes back — from the variant's own colour,
            // not from the pivot.
            ->assertJsonPath('summary.items.0.color', 'Lime')
            ->assertJsonPath('summary.items.0.color_hex', '#C6FF00')
            ->assertJsonPath('summary.items.0.size', 'M');

        $this->assertDatabaseHas('cart_items', [
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);
    }

    public function test_the_colour_pivot_does_not_change_what_a_variant_is_priced_at(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, $this->lime, $this->sizeM, 1890.0, 8);

        // With a pivot row present, the same variant must still be priced
        // and reported identically — the pivot must not shadow it.
        ProductColor::create([
            'product_id' => $product->id,
            'color_id' => $this->lime->id,
        ]);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertCreated()
            ->assertJsonPath('summary.items.0.price', 1890)
            ->assertJsonPath('summary.items.0.color', 'Lime')
            ->assertJsonPath('summary.items.0.color_hex', '#C6FF00');
    }

    // ─── Guest and signed-in carts ──────────────────────────────

    public function test_a_guest_cart_is_still_there_on_the_next_request(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, $this->onyx, $this->sizeM);

        // The client loads the summary on every page it renders, so the
        // guest's cart has to survive the round trip on the session.
        $this->persistSessionCookie(
            $this->postJson(route('cart.items.store'), [
                'variant_id' => $variant->id,
                'quantity' => 2,
            ])->assertCreated(),
        );

        $this->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonPath('item_count', 2)
            ->assertJsonPath('subtotal', 2980)
            ->assertJsonPath('items.0.variant_id', $variant->id);

        $this->assertDatabaseHas('cart_items', [
            'customer_id' => null,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);
    }

    public function test_a_guest_cart_and_a_signed_in_cart_stay_apart(): void
    {
        $product = $this->makeProduct();
        $guestVariant = $this->makeVariant($product, $this->onyx, $this->sizeM);
        $customerVariant = $this->makeVariant($product, $this->lime, $this->sizeL);

        $this->persistSessionCookie(
            $this->postJson(route('cart.items.store'), [
                'variant_id' => $guestVariant->id,
                'quantity' => 1,
            ])->assertCreated(),
        );

        // The signed-in customer sees their own cart only.
        $this->actingAs($this->customer)
            ->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonPath('item_count', 0);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), [
                'variant_id' => $customerVariant->id,
                'quantity' => 1,
            ])->assertCreated();

        $this->actingAs($this->customer)
            ->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonPath('item_count', 1)
            ->assertJsonPath('items.0.variant_id', $customerVariant->id);
    }

    // ─── Removing a line ─────────────────────────────────────────

    public function test_removing_a_line_takes_it_out_of_the_summary(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, $this->onyx, $this->sizeM);
        $other = $this->makeVariant($product, $this->lime, $this->sizeL);

        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $variant->id, 'quantity' => 1])
            ->assertCreated();
        $this->actingAs($this->customer)
            ->postJson(route('cart.items.store'), ['variant_id' => $other->id, 'quantity' => 1])
            ->assertCreated();

        $line = CartItem::where('variant_id', $variant->id)->firstOrFail();

        $this->actingAs($this->customer)
            ->deleteJson(route('cart.items.destroy', $line))
            ->assertNoContent();

        $this->assertDatabaseMissing('cart_items', ['id' => $line->id]);

        // Only the surviving line is left, and the total follows it.
        $this->actingAs($this->customer)
            ->getJson(route('cart.summary'))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.variant_id', $other->id)
            ->assertJsonPath('subtotal', 1490);
    }
}
