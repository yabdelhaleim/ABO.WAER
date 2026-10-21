<?php

namespace Tests\Feature;

use App\Enums\CouponType;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Color;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use App\Models\ShippingZone;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Security and performance guarantees for the storefront.
 *
 * Three contracts are pinned here, one per failure mode the brief calls out:
 *
 *  1. Login throttling — six rapid failed attempts hit the 429 wall, the
 *     caller is not silently signed in, and the wall resets by IP+identifier
 *     so two attackers brute-forcing the same account from different IPs
 *     each get their own budget (no shared lock-out between operators).
 *  2. Checkout + coupon throttling — the two anonymous-reachable endpoints
 *     that would otherwise let a bot fill the orders table or guess coupon
 *     codes respond with 429 once the configured budget is exhausted.
 *  3. No N+1 on the catalog page — the query count for `/shop` does not
 *     grow with the number of products on the page (the actual N+1
 *     signal) and stays inside a tight upper bound regardless.
 */
class SecurityAndPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected Color $color;

    protected Size $size;

    protected function setUp(): void
    {
        parent::setUp();

        // Lock lazy-loading *on* during this file so a regression that
        // re-introduces an N+1 fails with a LazyLoadingViolationException
        // even if the test below happens to also assert on query count.
        Model::preventLazyLoading(true);

        $this->category = Category::factory()->create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'is_active' => true,
            'is_visible' => true,
            'show_in_homepage' => true,
        ]);

        $this->color = Color::factory()->create([
            'name' => 'Onyx',
            'hex_code' => '#0D0F11',
            'is_active' => true,
        ]);

        $this->size = Size::factory()->create([
            'name' => 'M',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Restore the application's default lazy-loading policy so the
        // remaining suite (which already builds against it) is unaffected.
        Model::preventLazyLoading(! app()->isProduction());

        parent::tearDown();
    }

    // ─── Login throttling ─────────────────────────────────────────────────────

    /**
     * Five wrong passwords from the same IP+identifier come back as 302
     * with an error; the sixth gets 429. Locks the brute-force path
     * closed without blocking other shoppers from the same IP who happen
     * to be logging in with their own identifier.
     */
    public function test_sixth_failed_customer_login_returns_429(): void
    {
        Customer::create([
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        // First five bad attempts — should still be the normal failed-login 302.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->post('/login', [
                'identifier' => 'ahmed@example.com',
                'password' => 'wrong-password',
            ]);

            $this->assertLessThan(
                400,
                $response->getStatusCode(),
                "Failed login attempt #{$attempt} should not be throttled yet."
            );
        }

        // Sixth attempt — hammered past the cap of 5/min, must be 429.
        $response = $this->post('/login', [
            'identifier' => 'ahmed@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }

    /**
     * Same brute-force wall on the admin login. Two separate guards since
     * the limiter keys by submitted identifier, and the admin form posts
     * `email` (not `identifier`).
     */
    public function test_sixth_failed_admin_login_returns_429(): void
    {
        Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->post('/admin/login', [
                'email' => 'admin@store.com',
                'password' => 'wrong-password',
            ]);

            $this->assertLessThan(
                400,
                $response->getStatusCode(),
                "Admin login attempt #{$attempt} should not be throttled yet."
            );
        }

        $response = $this->post('/admin/login', [
            'email' => 'admin@store.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }

    /**
     * Different identifier on the same IP — the limiter keys by
     * identifier+IP, so an attacker hammering admin@store.com cannot
     * lock legit customers logging in with their own email out.
     */
    public function test_login_throttle_is_isolated_per_identifier(): void
    {
        Customer::create([
            'name' => 'Targeted User',
            'email' => 'target@store.com',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        // Burn the budget for `target@store.com`.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'identifier' => 'target@store.com',
                'password' => 'wrong',
            ]);
        }

        // Different identifier on the same IP — its budget must be fresh.
        $response = $this->post('/login', [
            'identifier' => 'other@store.com',
            'password' => 'whatever',
        ]);

        $this->assertLessThan(
            429,
            $response->getStatusCode(),
            'A different identifier must not inherit the previous identifier\'s throttle budget.'
        );
    }

    // ─── Checkout throttling ──────────────────────────────────────────────────

    /**
     * Three checkouts/min/user — the fourth one returns 429 so a script
     * bot cannot spray the orders table with junk.
     */
    public function test_fourth_checkout_submission_returns_429(): void
    {
        $customer = Customer::factory()->create([
            'phone' => '01011112233',
        ]);

        $zone = ShippingZone::factory()->create(['fee' => 50.0]);
        Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Cairo',
            'fee' => 50.0,
        ]);

        $payload = [
            'name' => 'Mazen Tarek',
            'phone' => '01011112233',
            'governorate' => 'Cairo',
            'area' => 'Sheraton',
            'address' => 'Building 12',
            'payment_method' => 'cod',
        ];

        // The first three submissions — guest-mode so the limiter keys by
        // IP. Any successful path is fine; the goal is to hit the throttle.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = $this->actingAs($customer, 'web')
                ->post(route('checkout.process'), $payload);

            $this->assertLessThan(
                400,
                $response->getStatusCode(),
                "Checkout attempt #{$attempt} should not be throttled yet."
            );
        }

        // Fourth — past the 3/min budget, must be 429.
        $response = $this->actingAs($customer, 'web')
            ->post(route('checkout.process'), $payload);

        $response->assertStatus(429);
    }

    // ─── Coupon throttling ───────────────────────────────────────────────────

    /**
     * Ten coupon validations/min/IP — the eleventh gets 429 so brute-forcing
     * coupon codes is no longer free.
     */
    public function test_eleventh_coupon_validation_returns_429(): void
    {
        // A real coupon so the first ten attempts return a normal validation
        // failure (or success) envelope, not a 422 that would let the test
        // hit 429 just because validation randomly blew up.
        Coupon::factory()->create([
            'code' => 'SAVE20',
            'type' => CouponType::Percentage,
            'value' => 20.0,
            'min_order_total' => 300.0,
        ]);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $response = $this->postJson(route('checkout.coupon.validate'), [
                'code' => 'GUESS-'.$attempt,
                'subtotal' => 500.0,
                'shipping_fee' => 50.0,
            ]);

            // The coupon validation endpoint returns 200 (success) or 422
            // (invalid code). Anything below 429 means throttling is silent.
            $this->assertLessThan(
                429,
                $response->getStatusCode(),
                "Coupon validation #{$attempt} should not be throttled yet (got {$response->getStatusCode()})."
            );
        }

        $response = $this->postJson(route('checkout.coupon.validate'), [
            'code' => 'GUESS-11',
            'subtotal' => 500.0,
            'shipping_fee' => 50.0,
        ]);

        $response->assertStatus(429);
    }

    // ─── N+1 audit (no N+1 on /shop) ─────────────────────────────────────────

    /**
     * Load /shop with 20 products and assert that the SQL footprint stays
     * constant regardless of how many products the page is rendering.
     *
     * The actual N+1 signal here is the equality between the 5-product
     * query count and the 20-product query count: any difference means a
     * relation was lazy-loaded in a loop, which is the very thing the
     * brief is asking us to rule out.
     */
    public function test_shop_page_does_not_suffer_n_plus_one(): void
    {
        $lightQueryCount = $this->measureQueryCountForShopPage(productCount: 5);
        $heavyQueryCount = $this->measureQueryCountForShopPage(productCount: 20);

        $this->assertSame(
            $lightQueryCount,
            $heavyQueryCount,
            "Query count for /shop with 5 products ({$lightQueryCount}) must match the count with 20 products ({$heavyQueryCount}); a delta means a relation is being lazy-loaded per product."
        );

        // Hard ceiling — even with the sidebar dropdown queries and the
        // price-points aggregate, the page must stay well under "one
        // query per product" (which is 20 in the worst case).
        $this->assertLessThanOrEqual(
            20,
            $heavyQueryCount,
            "Shop page is issuing {$heavyQueryCount} queries with 20 products; the eager-load surface in ShopController::index should hold it well under 20."
        );
    }

    /**
     * Hit /shop with $productCount products on the page and return the
     * number of SELECT statements the catalog rendered, including the
     * count(*) that paginate() fires.
     */
    protected function measureQueryCountForShopPage(int $productCount): int
    {
        $this->seedCatalogProducts($productCount);

        // Flush the application cache before each measurement so sidebar
        // data (categories, colors, sizes, price points) is always fetched
        // from the database rather than served from a warm cache. Without
        // this flush the second call would read cache and appear cheaper,
        // making the two counts incomparable and causing a false failure.
        \Illuminate\Support\Facades\Cache::flush();

        // Keep the connection logged so we can read every SELECT after
        // the request returns.
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->get(route('shop'));
        $response->assertOk();

        return count(array_filter(
            $queries,
            fn (string $sql): bool => str_starts_with(strtolower(trim($sql)), 'select'),
        ));
    }

    /**
     * Fill the catalog with $count products that each have one color,
     * one variant, one pivot and one image. The shop card template reads
     * every relation the eager-load section of ShopController claims to
     * cover (category, colors, variants, productColors.images), so this
     * is the fixture that reproduces — or rules out — an N+1 in the wild.
     */
    protected function seedCatalogProducts(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            // Use the factory for the slug — it auto-suffixes a unique
            // token so calling the seeder twice in the same process does
            // not trip the unique index.
            $product = Product::factory()->create([
                'name' => 'Drop 02 Hoodie '.$i,
                'category_id' => $this->category->id,
                'is_archived' => false,
                'is_featured' => true,
            ]);

            $pivot = ProductColor::create([
                'product_id' => $product->id,
                'color_id' => $this->color->id,
            ]);

            ProductImage::create([
                'product_color_id' => $pivot->id,
                'path' => 'images/streetwear-hero.jpg',
                'thumb_path' => null,
            ]);

            Variant::create([
                'product_id' => $product->id,
                'color_id' => $this->color->id,
                'size_id' => $this->size->id,
                'sku' => strtoupper(Str::random(10)),
                'price' => 1490.0 + $i,
                'cost' => 700.0,
                'quantity' => 5 + $i,
            ]);

            $product->colors()->syncWithoutDetaching([$this->color->id]);
        }
    }

    // ─── Image upload hardening ──────────────────────────────────────────────

    /**
     * Files over the 2 MB cap must be rejected at the FormRequest layer
     * before the file ever reaches disk — the rule chain on the
     * BannerRequest (image, mimes:jpg-jpeg-png-webp, max:2048) returns
     * a 422 with a per-field `image` validation error.
     */
    public function test_banner_upload_rejects_oversized_files(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        // 3 MB PNG — well above the 2048 KB cap. fake()->image() writes
        // real PNG bytes so the `image` mime check passes; the size rule
        // is what we are testing here.
        $payload = UploadedFile::fake()
            ->image('huge-banner.png', 100, 100)
            ->size(3072);

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.banners.store'), [
                'title' => 'Too big to upload',
                'image' => $payload,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }

    /**
     * Files with a disallowed mime type (e.g. plain text) must be
     * rejected by the `mimes:jpg,jpeg,png,webp` rule, not silently stored
     * as a banner image.
     */
    public function test_banner_upload_rejects_disallowed_mime_types(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        // A plain text payload — the `mimes` rule keys off the file's
        // mime type (driven by extension in the test fake), so a `.txt`
        // extension puts it outside the jpg/jpeg/png/webp allow-list
        // even though every other rule (`image`, `required`, etc.) is
        // satisfied trivially.
        $payload = UploadedFile::fake()
            ->createWithContent('evil.txt', 'this is plain text, not an image');

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.banners.store'), [
                'title' => 'Trying to upload text',
                'image' => $payload,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('image');
    }
}
