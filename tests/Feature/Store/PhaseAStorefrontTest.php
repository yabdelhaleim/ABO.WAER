<?php

namespace Tests\Feature\Store;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use App\Models\ShippingZone;
use App\Models\Size;
use App\Models\SizeGuide;
use App\Models\SizeGuideRow;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase A — the storefront flows that used to be fake.
 *
 * Four contracts are pinned here:
 *
 *  1. Checkout persists a real order. The submission goes to the
 *     server, the server reads the persisted cart, prices it from the
 *     variant rows, takes the stock off and answers with the order
 *     number. No order is ever assembled in the browser.
 *  2. The success page is rendered from that order row. The order
 *     number, the date, the payment method and the total all come from
 *     the database, so a refresh or a shared link shows the same
 *     confirmation.
 *  3. The product page's size chart comes from the size_guides tables.
 *     A category with no published guide shows the empty state — it
 *     never falls back to another category's numbers.
 *  4. Images resolve to a URL the browser can load. An admin upload
 *     lives on the public disk; a placeholder that ships with the app
 *     lives under public/. Both must resolve, and neither may 404.
 */
class PhaseAStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected Category $hoodies;

    protected Color $onyx;

    protected Size $sizeL;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoodies = Category::factory()->create([
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

        $this->sizeL = Size::factory()->create([
            'name' => 'L',
            'sort_order' => 3,
            'is_active' => true,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * A product with one colour, one variant and a cover photo.
     */
    protected function makeProduct(string $slug = 'drop-02-hoodie', float $price = 1890.0, int $stock = 10): Product
    {
        $product = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => $slug,
            'category_id' => $this->hoodies->id,
            'is_archived' => false,
        ]);

        // `product_colors` is both the colours pivot and the anchor for
        // the images, so the row is created once and the relation reads
        // it from there.
        $pivot = ProductColor::create([
            'product_id' => $product->id,
            'color_id' => $this->onyx->id,
        ]);

        ProductImage::create([
            'product_color_id' => $pivot->id,
            'path' => 'images/streetwear-hero.jpg',
            'sort_order' => 0,
        ]);

        Variant::create([
            'product_id' => $product->id,
            'color_id' => $this->onyx->id,
            'size_id' => $this->sizeL->id,
            'sku' => strtoupper(Str::random(10)),
            'price' => $price,
            'cost' => $price / 2,
            'discount_price' => null,
            'quantity' => $stock,
        ]);

        return $product->fresh();
    }

    /**
     * The delivery fields every checkout submission needs.
     *
     * @return array<string, string>
     */
    protected function deliveryPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mazen Tarek',
            'phone' => '01011223344',
            'governorate' => 'Cairo',
            'area' => 'Sheraton',
            'address' => 'Building 12',
            'payment_method' => 'cod',
        ], $overrides);
    }

    // ─── 1. Checkout persists a real order ────────────────────────

    public function test_checkout_submission_creates_a_real_order_row(): void
    {
        $zone = ShippingZone::factory()->create(['fee' => 50.00]);
        Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Cairo',
            'fee' => 50.00,
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create([
            'name' => 'Mazen Tarek',
            'phone' => '01011223344',
        ]);

        $product = $this->makeProduct();
        $variant = $product->variants->first();

        CartItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), $this->deliveryPayload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['order_number', 'redirect_url']);

        // The order number is the server's, and it matches the pattern
        // the success route accepts.
        $orderNumber = $response->json('order_number');
        $this->assertMatchesRegularExpression('/^ABO-\d{4}-\d{4}$/', $orderNumber);

        // The row exists with the figures the server read from the
        // database: 2 × 1890 subtotal, the zone's 50 shipping fee.
        $order = Order::query()->where('order_number', $orderNumber)->firstOrFail();

        $this->assertSame(3780.0, (float) $order->subtotal);
        $this->assertSame(50.0, (float) $order->shipping_fee);
        $this->assertSame(3830.0, (float) $order->total);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('Mazen Tarek', $order->customer_name);

        // One line item, priced from the variant row.
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'unit_price' => 1890.0,
            'total_price' => 3780.0,
        ]);
    }

    public function test_checkout_submission_takes_the_stock_off_the_variant(): void
    {
        $zone = ShippingZone::factory()->create(['fee' => 0.00]);
        Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Cairo',
            'fee' => 0.00,
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create();
        $product = $this->makeProduct(stock: 10);
        $variant = $product->variants->first();

        CartItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
        ]);

        $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), $this->deliveryPayload())
            ->assertCreated();

        $this->assertSame(7, $variant->fresh()->quantity);
    }

    public function test_checkout_submission_empties_the_cart(): void
    {
        $zone = ShippingZone::factory()->create(['fee' => 50.00]);
        Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Cairo',
            'fee' => 50.00,
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create();
        $product = $this->makeProduct();
        $variant = $product->variants->first();

        CartItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), $this->deliveryPayload())
            ->assertCreated();

        $this->assertDatabaseMissing('cart_items', ['customer_id' => $customer->id]);
    }

    public function test_checkout_without_a_cart_is_refused_and_creates_no_order(): void
    {
        $zone = ShippingZone::factory()->create(['fee' => 50.00]);
        Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Cairo',
            'fee' => 50.00,
            'is_active' => true,
        ]);

        $this->postJson(route('checkout.process'), $this->deliveryPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('cart');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_refuses_more_than_the_stock_left(): void
    {
        $zone = ShippingZone::factory()->create(['fee' => 0.00]);
        Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Cairo',
            'fee' => 0.00,
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create();
        $product = $this->makeProduct(stock: 2);
        $variant = $product->variants->first();

        CartItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 5,
        ]);

        $this->actingAs($customer, 'web')
            ->postJson(route('checkout.process'), $this->deliveryPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock');

        // Nothing was written and the stock is untouched.
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(2, $variant->fresh()->quantity);
    }

    // ─── 2. The success page is rendered from the order ───────────

    public function test_success_page_renders_the_values_of_the_stored_order(): void
    {
        $order = Order::factory()->create([
            'order_number' => 'ABO-2026-4242',
            'payment_method' => 'cod',
            'total' => 3830.0,
        ]);

        $response = $this->get(route('checkout.success', ['order' => $order->order_number]));

        $response->assertOk();
        $response->assertSee('ABO-2026-4242');
        $response->assertSee('Cash on delivery');
        $response->assertSee('3,830 EGP');
        $response->assertSee($order->created_at->format('D, d M Y · H:i'));
    }

    public function test_success_page_lists_the_items_of_the_stored_order(): void
    {
        $order = Order::factory()->create(['order_number' => 'ABO-2026-4243']);
        $order->items()->create([
            'product_name' => 'Drop 02 Hoodie',
            'color_name' => 'Onyx',
            'size_name' => 'L',
            'sku' => 'HOOD-ONYX-L',
            'unit_price' => 1890.0,
            'quantity' => 2,
            'total_price' => 3780.0,
        ]);

        $response = $this->get(route('checkout.success', ['order' => $order->order_number]));

        $response->assertOk();
        $response->assertSee('Drop 02 Hoodie');
        $response->assertSee('Onyx');
        $response->assertSee('Qty 2');
        $response->assertSee('3,780 EGP');
    }

    public function test_success_page_404s_for_an_order_that_does_not_exist(): void
    {
        $this->get('/checkout/success/ABO-2026-9999')->assertNotFound();
    }

    // ─── 3. The size guide comes from the database ────────────────

    public function test_product_page_renders_the_size_chart_of_its_category(): void
    {
        $guide = SizeGuide::create([
            'category_id' => $this->hoodies->id,
            'name' => 'Hoodies guide',
            'measurements' => ['Chest', 'Length', 'Sleeve', 'Shoulder'],
            'is_active' => true,
        ]);

        SizeGuideRow::create([
            'size_guide_id' => $guide->id,
            'size_name' => 'M',
            'measurements' => ['Chest' => 54.0, 'Length' => 68.0],
            'sort_order' => 0,
        ]);

        SizeGuideRow::create([
            'size_guide_id' => $guide->id,
            'size_name' => 'L',
            'measurements' => ['Chest' => 56.0, 'Length' => 70.0, 'Sleeve' => 61.0, 'Shoulder' => 46.0],
            'sort_order' => 1,
        ]);

        $product = $this->makeProduct();

        $response = $this->get(route('shop.show', ['slug' => $product->slug]));

        $response->assertOk();

        // The measurements the admin published are in the table.
        $response->assertSee('data-size-row="M"', false);
        $response->assertSee('data-size-row="L"', false);
        $response->assertSee('54');
        $response->assertSee('68');
        $response->assertSee('56');
        $response->assertSee('70');
        $response->assertSee('61');
        $response->assertSee('46');

        // The modal is bound to this product's category.
        $response->assertSee('data-product-category="hoodies"', false);
    }

    public function test_product_page_shows_the_empty_state_when_the_category_has_no_guide(): void
    {
        $product = $this->makeProduct();

        $response = $this->get(route('shop.show', ['slug' => $product->slug]));

        $response->assertOk();

        // No chart, so the empty state instead of a table of invented
        // numbers.
        $response->assertSee('MEASUREMENTS COMING SOON.');
        $response->assertDontSee('data-size-row="M"', false);
    }

    public function test_product_page_prints_the_note_and_the_how_to_the_admin_wrote(): void
    {
        $guide = SizeGuide::create([
            'category_id' => $this->hoodies->id,
            'name' => 'Hoodies guide',
            'measurements' => ['Chest'],
            'note' => 'Between two sizes? Take the larger one.',
            'how_to' => 'Lay the hoodie flat and measure the chest under the armhole.',
            'is_active' => true,
        ]);

        SizeGuideRow::create([
            'size_guide_id' => $guide->id,
            'size_name' => 'M',
            'measurements' => ['Chest' => 54.0],
            'sort_order' => 0,
        ]);

        $product = $this->makeProduct();

        $response = $this->get(route('shop.show', ['slug' => $product->slug]));

        $response->assertOk();
        $response->assertSee('Between two sizes? Take the larger one.');
        $response->assertSee('Lay the hoodie flat and measure the chest under the armhole.');

        // The guide's own copy replaces the generic guidance.
        $response->assertDontSee('Measured flat, 1 cm below the armhole.');
    }

    public function test_product_page_falls_back_to_the_generic_how_to_when_the_guide_has_none(): void
    {
        $guide = SizeGuide::create([
            'category_id' => $this->hoodies->id,
            'name' => 'Hoodies guide',
            'measurements' => ['Chest'],
            'is_active' => true,
        ]);

        SizeGuideRow::create([
            'size_guide_id' => $guide->id,
            'size_name' => 'M',
            'measurements' => ['Chest' => 54.0],
            'sort_order' => 0,
        ]);

        $product = $this->makeProduct();

        $response = $this->get(route('shop.show', ['slug' => $product->slug]));

        $response->assertOk();
        $response->assertSee('Measured flat, 1 cm below the armhole.');
    }

    public function test_product_page_does_not_show_another_categorys_measurements(): void
    {
        $other = Category::factory()->create([
            'name' => 'Jackets',
            'slug' => 'jackets',
            'is_active' => true,
            'is_visible' => true,
        ]);

        $guide = SizeGuide::create([
            'category_id' => $other->id,
            'name' => 'Jackets guide',
            'measurements' => ['Chest', 'Length'],
            'is_active' => true,
        ]);

        SizeGuideRow::create([
            'size_guide_id' => $guide->id,
            'size_name' => 'M',
            'measurements' => ['Chest' => 99.0, 'Length' => 99.0],
            'sort_order' => 0,
        ]);

        $product = $this->makeProduct();

        $response = $this->get(route('shop.show', ['slug' => $product->slug]));

        $response->assertOk();
        $response->assertDontSee('99');
    }

    // ─── 4. Images resolve to a loadable URL ──────────────────────

    public function test_a_product_image_resolves_to_a_url_the_browser_can_load(): void
    {
        $product = $this->makeProduct();

        $image = $product->primary_image;

        $this->assertNotNull($image);
        $this->assertSame(asset('images/streetwear-hero.jpg'), $image->url);
    }

    public function test_an_uploaded_product_image_resolves_through_the_storage_disk(): void
    {
        $product = $this->makeProduct();

        $uploaded = ProductImage::create([
            'product_color_id' => $product->productColors->first()->id,
            'path' => 'products/ULID/webp-cover.webp',
            'sort_order' => 1,
        ]);

        // The disk is asked first: an admin upload lives on the public
        // disk and is served through the /storage symlink.
        $this->assertSame(asset('storage/products/ULID/webp-cover.webp'), $uploaded->url);
    }

    public function test_a_category_cover_resolves_to_a_url(): void
    {
        $category = Category::factory()->create([
            'name' => 'Hoodies',
            'slug' => 'hoodies-2',
            'image' => 'images/streetwear-hero.jpg',
        ]);

        $this->assertSame(asset('images/streetwear-hero.jpg'), $category->image_url);
    }

    public function test_a_category_cover_resolves_through_the_storage_disk_when_uploaded(): void
    {
        $category = Category::factory()->create([
            'name' => 'Hoodies',
            'slug' => 'hoodies-3',
            'image' => 'categories/ULID/cover.jpg',
        ]);

        $this->assertSame(asset('storage/categories/ULID/cover.jpg'), $category->image_url);
    }

    public function test_the_shop_page_renders_a_cover_for_every_category(): void
    {
        $this->hoodies->update(['image' => 'images/streetwear-hero.jpg']);

        $response = $this->get(route('shop'));

        $response->assertOk();
        $response->assertSee(asset('images/streetwear-hero.jpg'), false);
    }
}
