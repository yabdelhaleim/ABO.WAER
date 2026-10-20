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
 * DB-driven coverage for the storefront catalog (Shop + Product detail).
 * Uses factories so each test owns its fixture and we never depend on
 * the static data file the views used to read.
 */
class ShopTest extends TestCase
{
    use RefreshDatabase;

    protected Category $hoodies;

    protected Category $tShirts;

    protected Color $onyx;

    protected Color $lime;

    protected Size $sizeM;

    protected Size $sizeL;

    protected function setUp(): void
    {
        parent::setUp();

        // Stable reference categories / colors / sizes every test reuses.
        $this->hoodies = Category::factory()->create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'is_active' => true,
            'is_visible' => true,
            'show_in_homepage' => true,
        ]);
        $this->tShirts = Category::factory()->create([
            'name' => 'T-Shirts',
            'slug' => 't-shirts',
            'is_active' => true,
            'is_visible' => true,
            'show_in_homepage' => true,
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

    /**
     * Attach colors + variants + a primary image to a product so the
     * views have something to render.
     */
    protected function attachCatalog(Product $product, float $price = 1490.0, int $stock = 8): void
    {
        foreach ([$this->onyx, $this->lime] as $color) {
            $pivot = ProductColor::firstOrCreate(
                ['product_id' => $product->id, 'color_id' => $color->id],
            );

            $product->colors()->syncWithoutDetaching([$color->id]);

            ProductImage::firstOrCreate(
                ['product_color_id' => $pivot->id, 'path' => 'images/streetwear-hero.jpg'],
                ['thumb_path' => null, 'sort_order' => 0],
            );

            foreach ([$this->sizeM, $this->sizeL] as $size) {
                Variant::firstOrCreate(
                    [
                        'product_id' => $product->id,
                        'color_id' => $color->id,
                        'size_id' => $size->id,
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
        }
    }

    public function test_shop_page_displays_active_products(): void
    {
        $visible = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'category_id' => $this->hoodies->id,
            'is_archived' => false,
        ]);
        $this->attachCatalog($visible);

        // Another product in a sibling category — should also show because
        // no filter is applied.
        Product::factory()->create([
            'name' => 'Urban Tee — Black',
            'slug' => 'urban-tee-black',
            'category_id' => $this->tShirts->id,
            'is_archived' => false,
        ]);

        $response = $this->get(route('shop'));

        $response->assertOk();
        $response->assertSee('Drop 02 Hoodie', false);
        $response->assertSee('Urban Tee — Black', false);
        $response->assertSee('data-shop-grid', false);
        $response->assertSee('1,490 EGP', false);
        // Two visible cards rendered.
        $this->assertSame(
            2,
            substr_count($response->getContent(), 'data-product'),
        );
    }

    public function test_archived_products_are_hidden_from_shop(): void
    {
        Product::factory()->create([
            'name' => 'Active Hoodie',
            'slug' => 'active-hoodie',
            'category_id' => $this->hoodies->id,
            'is_archived' => false,
        ]);
        Product::factory()->archived()->create([
            'name' => 'Archived Hoodie',
            'slug' => 'archived-hoodie',
            'category_id' => $this->hoodies->id,
        ]);

        $response = $this->get(route('shop'));

        $response->assertOk();
        $response->assertSee('Active Hoodie', false);
        $response->assertDontSee('Archived Hoodie', false);
    }

    public function test_shop_can_filter_by_category(): void
    {
        $hoodie = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'category_id' => $this->hoodies->id,
        ]);
        $this->attachCatalog($hoodie);

        $tee = Product::factory()->create([
            'name' => 'Urban Tee — Black',
            'slug' => 'urban-tee-black',
            'category_id' => $this->tShirts->id,
        ]);
        $this->attachCatalog($tee, price: 690.0);

        $response = $this->get(route('shop', ['category' => 'hoodies']));

        $response->assertOk();
        $response->assertSee('Drop 02 Hoodie', false);
        $response->assertDontSee('Urban Tee — Black', false);

        $response = $this->get(route('shop', ['category' => 't-shirts']));
        $response->assertOk();
        $response->assertSee('Urban Tee — Black', false);
        $response->assertDontSee('Drop 02 Hoodie', false);
    }

    public function test_shop_can_filter_by_color_and_size(): void
    {
        $product = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'category_id' => $this->hoodies->id,
        ]);

        // Build variants only for one color / size combination so the
        // size + color filters actually narrow the catalog.
        $pivot = ProductColor::create([
            'product_id' => $product->id,
            'color_id' => $this->lime->id,
        ]);
        ProductImage::create([
            'product_color_id' => $pivot->id,
            'path' => 'images/streetwear-hero.jpg',
        ]);
        Variant::create([
            'product_id' => $product->id,
            'color_id' => $this->lime->id,
            'size_id' => $this->sizeL->id,
            'sku' => strtoupper(Str::random(10)),
            'price' => 1890,
            'cost' => 800,
            'quantity' => 5,
        ]);
        Variant::create([
            'product_id' => $product->id,
            'color_id' => $this->onyx->id,
            'size_id' => $this->sizeM->id,
            'sku' => strtoupper(Str::random(10)),
            'price' => 1890,
            'cost' => 800,
            'quantity' => 0, // out of stock — should be excluded by the size filter.
        ]);

        // Color by id narrows to the lime variant.
        $response = $this->get(route('shop', ['color' => $this->lime->id]));
        $response->assertOk();
        $response->assertSee('Drop 02 Hoodie', false);

        // Color by hex also works (URL stability).
        $response = $this->get(route('shop', ['color' => '#C6FF00']));
        $response->assertOk();
        $response->assertSee('Drop 02 Hoodie', false);

        // Size filter only counts variants in stock; nothing matches size M
        // because that variant has 0 stock.
        $response = $this->get(route('shop', ['size' => $this->sizeM->id]));
        $response->assertOk();
        $this->assertStringNotContainsString(
            'Drop 02 Hoodie',
            $response->getContent(),
            'Product must be hidden when only out-of-stock variants match the size filter.',
        );

        // Size L is in stock — product should appear.
        $response = $this->get(route('shop', ['size' => $this->sizeL->id]));
        $response->assertOk();
        $response->assertSee('Drop 02 Hoodie', false);
    }

    public function test_product_detail_page_loads_with_variants(): void
    {
        $product = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'category_id' => $this->hoodies->id,
            'is_featured' => true,
        ]);
        $this->attachCatalog($product, price: 1890.0);

        $response = $this->get(route('shop.show', ['slug' => 'drop-02-hoodie']));

        $response->assertOk();
        $response->assertSee('Drop 02 Hoodie', false);
        $response->assertSee('1,890 EGP', false);
        $response->assertSee('data-product-page', false);
        $response->assertSee('data-product-variants', false);
        $response->assertSee('data-color-id="'.$this->onyx->id.'"', false);
        $response->assertSee('data-color-id="'.$this->lime->id.'"', false);
        // The shop-link "More from this category" section requires a sibling.
        $sibling = Product::factory()->create([
            'name' => 'Drop 02 Hoodie — Zip',
            'slug' => 'drop-02-hoodie-zip',
            'category_id' => $this->hoodies->id,
        ]);
        $this->attachCatalog($sibling);

        $response = $this->get(route('shop.show', ['slug' => 'drop-02-hoodie']));
        $response->assertOk();
        $response->assertSee('Drop 02 Hoodie — Zip', false);
    }

    public function test_non_existent_product_slug_returns_404(): void
    {
        $response = $this->get(route('shop.show', ['slug' => 'this-product-does-not-exist']));

        $response->assertNotFound();
    }
}
