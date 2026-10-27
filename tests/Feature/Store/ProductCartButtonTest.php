<?php

namespace Tests\Feature\Store;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The product page half of the server-backed cart.
 *
 * The shopper picks a colour and a size; the page has to hand the script
 * the id of the exact variant row behind that choice, so the cart stores
 * the piece the shopper actually picked rather than "some variant of this
 * product". These tests pin that mapping, and the safety rails around it:
 * a variant of another product is never listed, and a colour missing from
 * the product_colors pivot still gets its sizes.
 */
class ProductCartButtonTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected Color $onyx;

    protected Color $lime;

    protected Size $sizeM;

    protected Size $sizeL;

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
    }

    // ─── Helpers ─────────────────────────────────────────────────

    protected function makeProduct(string $slug = 'drop-02-hoodie'): Product
    {
        return Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => $slug,
            'category_id' => $this->category->id,
            'is_featured' => true,
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
     * Attach a product_colors row (and the colour relation) so the page has
     * a gallery for that colour.
     */
    protected function attachPivot(Product $product, Color $color): void
    {
        ProductColor::firstOrCreate([
            'product_id' => $product->id,
            'color_id' => $color->id,
        ]);

        $product->colors()->syncWithoutDetaching([$color->id]);
    }

    /**
     * The JSON blob product.blade.php publishes for product.js.
     *
     * @return array<string, mixed>
     */
    protected function variantsPayload(Product $product): array
    {
        $response = $this->get(route('shop.show', ['slug' => $product->slug]));

        $response->assertOk();

        preg_match(
            '/<script type="application\/json" data-product-variants>(.+?)<\/script>/s',
            $response->getContent() ?: '',
            $m
        );

        $this->assertArrayHasKey(1, $m, 'the product page must publish the variants JSON payload');

        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded, 'the product page variants JSON must be an array');

        return $decoded;
    }

    /**
     * The variant id the page lists for a colour × size pair.
     */
    protected function variantIdFor(array $payload, int $colorId, int $sizeId): mixed
    {
        $block = collect($payload['colors'])->firstWhere('id', $colorId);
        $this->assertNotNull($block, "colour {$colorId} must be listed in the payload");

        $size = collect($block['sizes'])->firstWhere('id', $sizeId);
        $this->assertNotNull($size, "size {$sizeId} must be listed for colour {$colorId}");

        return $size['variant_id'] ?? null;
    }

    // ─── The payload names the exact variant ─────────────────────

    public function test_every_size_carries_the_id_of_the_variant_behind_it(): void
    {
        $product = $this->makeProduct();
        $this->attachPivot($product, $this->onyx);
        $this->attachPivot($product, $this->lime);

        $onyxM = $this->makeVariant($product, $this->onyx, $this->sizeM);
        $onyxL = $this->makeVariant($product, $this->onyx, $this->sizeL);
        $limeM = $this->makeVariant($product, $this->lime, $this->sizeM);

        $payload = $this->variantsPayload($product);

        $this->assertSame($product->slug, $payload['slug']);
        $this->assertSame($onyxM->id, $this->variantIdFor($payload, $this->onyx->id, $this->sizeM->id));
        $this->assertSame($onyxL->id, $this->variantIdFor($payload, $this->onyx->id, $this->sizeL->id));
        $this->assertSame($limeM->id, $this->variantIdFor($payload, $this->lime->id, $this->sizeM->id));
    }

    public function test_a_variant_id_is_never_taken_from_another_product(): void
    {
        $product = $this->makeProduct();
        $other = $this->makeProduct('drop-02-hoodie-zip');

        $this->attachPivot($product, $this->onyx);
        $this->attachPivot($other, $this->lime);

        $mine = $this->makeVariant($product, $this->onyx, $this->sizeM);
        $theirs = $this->makeVariant($other, $this->lime, $this->sizeL);

        $payload = $this->variantsPayload($product);

        $listed = collect($payload['colors'])
            ->flatMap(fn (array $block) => collect($block['sizes'])->pluck('variant_id'))
            ->all();

        $this->assertContains($mine->id, $listed);
        $this->assertNotContains(
            $theirs->id,
            $listed,
            'the product page must never offer a variant that belongs to another product'
        );
    }

    public function test_the_payload_reports_the_stock_and_the_price_of_each_size(): void
    {
        $product = $this->makeProduct();
        $this->attachPivot($product, $this->onyx);

        $this->makeVariant($product, $this->onyx, $this->sizeM, 1890.0, 4);
        $this->makeVariant($product, $this->onyx, $this->sizeL, 1890.0, 0);

        $payload = $this->variantsPayload($product);

        $block = collect($payload['colors'])->firstWhere('id', $this->onyx->id);

        $m = collect($block['sizes'])->firstWhere('id', $this->sizeM->id);
        $this->assertSame(4, $m['stock']);
        $this->assertSame(1890.0, (float) $m['price']);

        // A sold-out size is listed — the shopper has to be able to see
        // that it exists and is gone — and it says so with a stock of 0.
        $l = collect($block['sizes'])->firstWhere('id', $this->sizeL->id);
        $this->assertSame(0, $l['stock']);
    }

    public function test_a_discounted_variant_is_listed_at_the_discounted_price(): void
    {
        $product = $this->makeProduct();
        $this->attachPivot($product, $this->onyx);

        $this->makeVariant($product, $this->onyx, $this->sizeM, 1890.0, 4, discount: 1490.0);

        $payload = $this->variantsPayload($product);

        $block = collect($payload['colors'])->firstWhere('id', $this->onyx->id);
        $m = collect($block['sizes'])->firstWhere('id', $this->sizeM->id);

        $this->assertSame(1490.0, (float) $m['price']);
    }

    public function test_a_fractional_price_is_listed_without_being_rounded(): void
    {
        $product = $this->makeProduct();
        $this->attachPivot($product, $this->onyx);

        $this->makeVariant($product, $this->onyx, $this->sizeM, 499.75, 4);

        $payload = $this->variantsPayload($product);

        $block = collect($payload['colors'])->firstWhere('id', $this->onyx->id);
        $m = collect($block['sizes'])->firstWhere('id', $this->sizeM->id);

        $this->assertSame(499.75, (float) $m['price']);
    }

    // ─── The pivot is not the source of truth ────────────────────

    public function test_a_colour_without_a_pivot_row_still_lists_its_sizes(): void
    {
        $product = $this->makeProduct();
        $this->attachPivot($product, $this->onyx);

        // Lime has a variant but no product_colors row — the exact shape
        // found in the development database.
        $limeM = $this->makeVariant($product, $this->lime, $this->sizeM);

        $this->assertDatabaseMissing('product_colors', [
            'product_id' => $product->id,
            'color_id' => $this->lime->id,
        ]);

        $payload = $this->variantsPayload($product);

        // The colour is listed, with its sizes, and with the id of the
        // variant that will actually be added.
        $this->assertSame(
            $limeM->id,
            $this->variantIdFor($payload, $this->lime->id, $this->sizeM->id),
            'a valid choice must not be dropped because the colour has no pivot row'
        );
    }

    // ─── The button ──────────────────────────────────────────────

    public function test_the_add_to_cart_button_is_there_and_waits_for_a_choice(): void
    {
        $product = $this->makeProduct();
        $this->attachPivot($product, $this->onyx);
        $this->makeVariant($product, $this->onyx, $this->sizeM);

        $html = $this->get(route('shop.show', ['slug' => $product->slug]))->assertOk()->getContent() ?: '';

        $this->assertStringContainsString('data-product-atc', $html);

        // The button is disabled in the markup: without the script there
        // is no chosen variant to add, and a half-chosen colour × size
        // must not be addable.
        preg_match('/<button[^>]*data-product-atc[^>]*>/s', $html, $m);
        $this->assertNotEmpty($m, 'the product page must carry an add-to-cart button');
        $this->assertStringContainsString(
            'disabled',
            $m[0],
            'the add-to-cart button must ship disabled and be enabled by the script'
        );

        // It is no longer the "coming soon" placeholder.
        $this->assertStringNotContainsString('ADD TO CART — SOON', $html);
        $this->assertStringContainsString('ADD TO CART', $html);
    }

    public function test_the_product_page_has_a_toast_host_for_the_add_result(): void
    {
        $product = $this->makeProduct();
        $this->attachPivot($product, $this->onyx);
        $this->makeVariant($product, $this->onyx, $this->sizeM);

        $html = $this->get(route('shop.show', ['slug' => $product->slug]))->assertOk()->getContent() ?: '';

        // The client reports a successful and a refused add through the
        // storefront's own toast slot — without it the shopper would get
        // no feedback at all.
        $this->assertStringContainsString('data-cart-toasts', $html);
    }

    public function test_the_product_page_ships_the_script_that_enables_the_button(): void
    {
        $product = $this->makeProduct();
        $this->attachPivot($product, $this->onyx);
        $this->makeVariant($product, $this->onyx, $this->sizeM);

        $html = $this->get(route('shop.show', ['slug' => $product->slug]))->assertOk()->getContent() ?: '';

        // The Vite entry that carries product.js. Its hash is base64url,
        // so it carries "-" and "_" as well.
        $this->assertMatchesRegularExpression(
            '/<script[^>]+src="[^"]*app-[A-Za-z0-9_-]+\.js"/',
            $html
        );
    }

    // ─── The script sends the variant id, not a guess ────────────

    public function test_the_product_script_posts_the_variant_id_to_the_cart_api(): void
    {
        $source = file_get_contents(resource_path('js/product.js'));

        $this->assertNotFalse($source);

        // The cart is keyed by variant id, so that is what travels.
        $this->assertStringContainsString('variant_id: picked.variant.variant_id', $source);
        $this->assertStringContainsString('quantity: 1', $source);

        // And it posts to the endpoint the server actually exposes.
        $this->assertStringContainsString("'".route('cart.items.store', [], false)."'", $source);

        // A refused add is reported, not swallowed: the button is only
        // re-enabled and the toast only says "added" on a real success.
        $this->assertStringContainsString('if (!result.ok)', $source);
        $this->assertStringContainsString('showToast', $source);
    }

    public function test_the_product_script_refuses_a_choice_without_stock(): void
    {
        $source = file_get_contents(resource_path('js/product.js'));

        $this->assertNotFalse($source);

        // Out of stock is caught before the request, so the shopper gets
        // an instant answer instead of a round trip that fails.
        $this->assertStringContainsString('Number(picked.variant.stock) < 1', $source);
        $this->assertStringContainsString('That size is out of stock.', $source);
    }
}
