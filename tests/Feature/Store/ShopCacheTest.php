<?php

namespace Tests\Feature\Store;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\Variant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Regression guard for the shop sidebar cache.
 *
 * config/cache.php sets `serializable_classes => false` — Laravel's
 * default defence against PHP gadget-chain attacks — so every cache
 * store unserializes with `allowed_classes => false`. Any object in the
 * payload comes back as `__PHP_Incomplete_Class`, and the first
 * property read on it raises a fatal error.
 *
 * The suite's default cache driver is `array`, which keeps values
 * unserialized in memory (`cache.stores.array.serialize => false`), so
 * the production driver is the only one that exercises the round trip.
 * These tests therefore pin the driver to `database` — the same driver
 * .env selects — and assert that a cache HIT still renders.
 */
class ShopCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The production driver. The `cache` table exists in the test
        // schema, so the store round trip runs for real.
        config()->set('cache.default', 'database');
        Cache::purge('database');
        Cache::store('database')->clear();

        $this->seed(CatalogSeeder::class);
    }

    public function test_shop_page_survives_a_cache_hit_on_the_sidebar_filters(): void
    {
        // First request: cache miss. The payload is written.
        $this->get('/shop')->assertOk();

        // Second request: cache hit. The payload is read back through
        // `unserialize(..., ['allowed_classes' => false])`.
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('hoodies', false);
    }

    public function test_the_cached_sidebar_payload_contains_no_objects(): void
    {
        $this->get('/shop')->assertOk();

        $raw = Cache::store('database')->get('shop.sidebar.categories');

        $this->assertIsArray($raw);
        $this->assertNotEmpty($raw);

        foreach ($raw as $row) {
            $this->assertIsArray($row, 'Cached sidebar rows must be arrays, not objects.');
        }
    }

    public function test_a_cache_hit_does_not_downgrade_the_sidebar_to_empty(): void
    {
        $this->get('/shop')->assertOk();

        $cached = Cache::store('database')->get('shop.sidebar.categories');

        $this->assertIsArray($cached);
        $this->assertNotEmpty(
            $cached,
            'The seeded catalog must produce a non-empty sidebar cache payload.'
        );
    }

    public function test_variants_and_prices_still_reach_the_shop_grid(): void
    {
        $category = Category::where('slug', 'hoodies')->firstOrFail();

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Cache Regression Hoodie',
            'slug' => 'cache-regression-hoodie',
            'is_archived' => false,
        ]);

        $color = Color::first() ?? Color::create([
            'name' => 'Onyx',
            'hex_code' => '#0D0F11',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $size = Size::first() ?? Size::create([
            'name' => 'M',
            'group' => 'letter',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Variant::create([
            'product_id' => $product->id,
            'color_id' => $color->id,
            'size_id' => $size->id,
            'sku' => 'CACHE-REGRESSION-001',
            'price' => 1490,
            'cost' => 600,
            'quantity' => 12,
        ]);

        $this->get('/shop')->assertOk();

        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('Cache Regression Hoodie');
        $response->assertSee('1,490');
    }
}
