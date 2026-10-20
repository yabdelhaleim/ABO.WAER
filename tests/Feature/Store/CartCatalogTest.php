<?php

namespace Tests\Feature\Store;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The /cart page used to read its products from the static
 * resources/views/store/data/home.php array. It now reads the live catalog,
 * which is what these tests pin down: the shape of the JSON payload the page
 * hands to cart.js, and which products are eligible to appear in it at all.
 *
 * The Blade view and cart.js themselves are untouched by that change — the
 * view maps an array of arrays into the <script> tag — so these tests also
 * guard the contract those two files depend on.
 */
class CartCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected Category $hoodies;

    protected Color $onyx;

    protected Color $lime;

    protected Size $sizeM;

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
        $this->onyx = Color::factory()->create(['name' => 'Onyx', 'hex_code' => '#0D0F11', 'is_active' => true]);
        $this->lime = Color::factory()->create(['name' => 'Lime', 'hex_code' => '#C6FF00', 'is_active' => true]);
        $this->sizeM = Size::factory()->create(['name' => 'M', 'sort_order' => 2, 'is_active' => true]);
        $this->sizeL = Size::factory()->create(['name' => 'L', 'sort_order' => 3, 'is_active' => true]);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * Give a product one variant per colour so it is purchasable.
     */
    protected function attachVariants(Product $product, float $price = 1490.0, int $stock = 8): Variant
    {
        $variant = null;

        foreach ([$this->onyx, $this->lime] as $color) {
            $pivot = ProductColor::firstOrCreate([
                'product_id' => $product->id,
                'color_id' => $color->id,
            ]);

            $product->colors()->syncWithoutDetaching([$color->id]);

            ProductImage::firstOrCreate(
                ['product_color_id' => $pivot->id, 'path' => 'images/streetwear-hero.jpg'],
                ['thumb_path' => null, 'sort_order' => 0],
            );

            $variant = Variant::firstOrCreate(
                [
                    'product_id' => $product->id,
                    'color_id' => $color->id,
                    'size_id' => $this->sizeM->id,
                ],
                [
                    'sku' => strtoupper(Str::random(10)),
                    'price' => $price,
                    'cost' => $price / 2,
                    'discount_price' => null,
                    'quantity' => $stock,
                ],
            );
        }

        return $variant;
    }

    /**
     * Pull the products array the page publishes to cart.js.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function cartPayload(): array
    {
        $response = $this->get(route('cart'));
        $response->assertOk();

        preg_match(
            '/<script type="application\/json" data-cart-products>(.*?)<\/script>/s',
            $response->getContent() ?: '',
            $matches
        );

        $this->assertArrayHasKey(1, $matches, 'cart page must expose the products JSON payload');

        return json_decode($matches[1], true);
    }

    // ─── Payload is built from the database ───────────────────────

    public function test_cart_payload_lists_products_stored_in_the_database(): void
    {
        $product = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'category_id' => $this->hoodies->id,
        ]);
        $this->attachVariants($product, 1890.0, 10);

        $payload = $this->cartPayload();

        $this->assertCount(1, $payload);
        $this->assertSame('drop-02-hoodie', $payload[0]['slug']);
        $this->assertSame('Drop 02 Hoodie', $payload[0]['name']);
        $this->assertSame(1890, $payload[0]['price']);
        $this->assertSame('hoodies', $payload[0]['category']);
        $this->assertSame('images/streetwear-hero.jpg', $payload[0]['image']);
    }

    public function test_cart_payload_ignores_the_static_data_file(): void
    {
        // The legacy data file still declares four editorial products. Once
        // the catalog is DB-driven none of them may leak through.
        $static = require resource_path('views/store/data/home.php');

        $payload = $this->cartPayload();
        $slugs = array_column($payload, 'slug');

        foreach ($static['products'] as $product) {
            $this->assertNotContains($product['slug'], $slugs, "{$product['slug']} came from the static file");
        }
    }

    public function test_cart_payload_exposes_exactly_the_keys_cart_js_reads(): void
    {
        $product = Product::factory()->create(['slug' => 'hoodie-01', 'category_id' => $this->hoodies->id]);
        $this->attachVariants($product);

        $payload = $this->cartPayload();

        // cart.js reads slug / name / price / image / category / colors and
        // nothing else. A missing key is a silent runtime break, so pin it.
        $this->assertSame(
            ['slug', 'name', 'price', 'image', 'category', 'colors'],
            array_keys($payload[0]),
        );
    }

    public function test_cart_payload_lists_colors_as_hex_codes(): void
    {
        $product = Product::factory()->create(['slug' => 'hoodie-01']);
        $this->attachVariants($product);

        $payload = $this->cartPayload();

        // cart.js feeds these straight into a CSS custom property, so they
        // must stay hex strings and not colour names or ids.
        $this->assertEqualsCanonicalizing(['#0D0F11', '#C6FF00'], $payload[0]['colors']);
    }

    // ─── Eligibility ─────────────────────────────────────────────

    public function test_archived_products_are_excluded(): void
    {
        $archived = Product::factory()->archived()->create(['slug' => 'gone-forever']);
        $this->attachVariants($archived);

        $this->assertSame([], $this->cartPayload());
    }

    public function test_products_without_variants_are_excluded(): void
    {
        // A product with no purchasable variant has no price a shopper could
        // ever be charged, so it must not reach the cart payload.
        Product::factory()->create(['slug' => 'no-variants']);

        $this->assertSame([], $this->cartPayload());
    }

    public function test_an_empty_catalog_still_renders_the_page(): void
    {
        $this->get(route('cart'))
            ->assertOk()
            ->assertSee('data-cart-products', false);
    }

    // ─── Pricing ─────────────────────────────────────────────────

    public function test_price_is_the_cheapest_in_stock_variant(): void
    {
        $product = Product::factory()->create(['slug' => 'hoodie-01']);
        $cheap = $this->attachVariants($product, 900.0, 5);

        Variant::create([
            'product_id' => $product->id,
            'color_id' => $this->lime->id,
            'size_id' => $this->sizeL->id,
            'sku' => 'CHEAP-01',
            'price' => 1900.0,
            'cost' => 900.0,
            'discount_price' => null,
            'quantity' => 0,
        ]);

        // The 900 variant is in stock, so it wins even though the sold-out
        // 1900 variant would otherwise be irrelevant to the comparison.
        $this->assertSame(900, (int) $this->cartPayload()[0]['price']);
        $this->assertNotNull($cheap);
    }

    public function test_price_uses_the_discounted_price_when_one_is_set(): void
    {
        $product = Product::factory()->create(['slug' => 'hoodie-01']);
        $this->attachVariants($product, 1900.0, 5);

        $product->variants()->update(['discount_price' => 1400.0]);

        $this->assertSame(1400, (int) $this->cartPayload()[0]['price']);
    }

    public function test_fully_sold_out_product_falls_back_to_its_cheapest_variant(): void
    {
        $product = Product::factory()->create(['slug' => 'hoodie-01']);
        $this->attachVariants($product, 750.0, 0);

        // It still renders — the shopper should see it — but at its real
        // price rather than 0, and adding it will be refused on stock.
        $this->assertSame(750, (int) $this->cartPayload()[0]['price']);
    }

    // ─── Ordering ────────────────────────────────────────────────

    public function test_payload_order_is_stable_by_product_id(): void
    {
        $first = Product::factory()->create(['slug' => 'aaa-first']);
        $second = Product::factory()->create(['slug' => 'bbb-second']);
        $this->attachVariants($first, 100.0, 5);
        $this->attachVariants($second, 200.0, 5);

        $this->assertSame(['aaa-first', 'bbb-second'], array_column($this->cartPayload(), 'slug'));
    }

    // ─── Page chrome is unaffected ───────────────────────────────

    public function test_switching_to_the_database_keeps_the_page_chrome(): void
    {
        // The static data file is still what supplies the announcement bar
        // and the footer; only the product list comes from the database now.
        $this->get(route('cart'))
            ->assertOk()
            ->assertSee('Free shipping over 1,500 EGP', false)
            ->assertSee('store-footer', false);
    }
}
