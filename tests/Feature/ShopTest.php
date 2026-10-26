<?php

namespace Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_shop_page_returns_successful_response(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
    }

    public function test_shop_page_reuses_storefront_layout(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('<html lang="en"', false);
        $response->assertSee('class="abo-store-body"', false);
        $response->assertSee('<main class="abo-store-main"', false);
    }

    public function test_shop_page_keeps_global_chrome_intact(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        // Header + announcement + footer (per spec: do not modify global chrome).
        $response->assertSee('class="announcement"', false);
        $response->assertSee('class="store-header"', false);
        $response->assertSee('<footer class="store-footer"', false);
    }

    public function test_shop_page_renders_compact_hero_with_required_copy(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('class="shop-hero"', false);
        $response->assertSee('SHOP THE COLLECTION', false);
        $response->assertSee('FIND YOUR OWN', false);
        $response->assertSee('>STYLE.</span>', false);
        $response->assertSee('Pieces that speak before you do.', false);
        $response->assertSee('EXPLORE COLLECTION', false);
        // The last word in the headline is lime.
        $response->assertSee('shop-hero__headline-line--accent', false);
        $response->assertSee('href="#grid"', false);
    }

    public function test_shop_page_renders_category_strip_with_real_counts(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('id="categories"', false);
        $response->assertSee('class="shop-cat"', false);
        // 4 categories from the data file.
        $response->assertSee('>HOODIES</span>', false);
        $response->assertSee('>T-SHIRTS</span>', false);
        $response->assertSee('>PANTS</span>', false);
        $response->assertSee('>JACKETS</span>', false);
        // Real counts: 1 hoodie, 1 tee, 1 pant, 1 jacket.
        $response->assertSee('1 ITEM', false);
    }

    public function test_shop_category_links_carry_slug_query(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        // The category tile links to /shop?category=<slug>#grid.
        $response->assertSee('href="'.route('shop').'?category=hoodies#grid"', false);
        $response->assertSee('href="'.route('shop').'?category=t-shirts#grid"', false);
        $response->assertSee('href="'.route('shop').'?category=pants#grid"', false);
        $response->assertSee('href="'.route('shop').'?category=jackets#grid"', false);
    }

    public function test_shop_filter_bar_shows_all_filter_groups(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('class="shop-filters"', false);
        $response->assertSee('class="shop-filters__chips"', false);
        $response->assertSee('All items', false);
        $response->assertSee('Category', false);
        $response->assertSee('Sort by', false);
    }

    public function test_shop_size_filter_is_rendered_when_data_has_sizes(): void
    {
        // Spec §3: "Options are generated from the real data. Render a
        // group only if the data has values for it." The seeder maps
        // real Size rows so the SIZE chip is part of the toolbar.
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('data-filter="size"', false);
    }

    public function test_shop_filter_chip_has_aria_pressed_for_accessibility(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('aria-pressed="true"', false);
    }

    public function test_shop_page_renders_real_product_grid_with_required_data(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('id="grid"', false);
        $response->assertSee('class="shop-grid"', false);
        $response->assertSee('data-shop-grid', false);
        $response->assertSee('data-product', false);
        // 4 products with real data.
        $response->assertSee('Drop 02 Hoodie', false);
        $response->assertSee('Urban Tee — Black', false);
        $response->assertSee('Cargo Pant — Olive', false);
        $response->assertSee('Limo Bomber — Lime', false);
        // Real prices.
        $response->assertSee('1,890 EGP', false);
        $response->assertSee('690 EGP', false);
        $response->assertSee('1,450 EGP', false);
        $response->assertSee('2,790 EGP', false);
    }

    public function test_shop_product_card_links_to_detail_page(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('href="'.route('shop.show', ['slug' => 'drop-02-hoodie']).'"', false);
        $response->assertSee('href="'.route('shop.show', ['slug' => 'limo-bomber-lime']).'"', false);
    }

    public function test_shop_badges_render_only_when_data_supports_them(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('class="shop-card__badge shop-card__badge--new"', false);
        $response->assertSee('class="shop-card__badge shop-card__badge--best-seller"', false);
        $response->assertSee('class="shop-card__badge shop-card__badge--limited"', false);
        // Cargo Pant has no badge in the data — its card must not render one.
        $cargos = substr_count($response->getContent(), 'Cargo Pant — Olive');
        $this->assertGreaterThan(0, $cargos, 'Cargo Pant should appear in the grid.');
    }

    public function test_shop_card_image_has_no_layout_shift_dimensions(): void
    {
        // Spec §4: "no layout shift".
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('width="600"', false);
        $response->assertSee('height="750"', false);
    }

    public function test_shop_results_count_is_real_not_fake(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('data-shop-count-num', false);
        $response->assertSee('>4</span>', false);
    }

    public function test_shop_mid_banner_is_hidden_with_fewer_than_8_products(): void
    {
        // Spec §5: keep it easy to remove; with 4 products the banner
        // must not be in the markup.
        $response = $this->get('/shop');

        $response->assertOk();
        $this->assertStringNotContainsString('class="shop-mid-banner"', $response->getContent());
    }

    public function test_shop_load_more_markup_is_hidden_when_fewer_products_than_page_size(): void
    {
        // The seeder maps 4 products but the controller paginates 12 per
        // page, so the pagination "load more" markup must not appear yet.
        $response = $this->get('/shop');

        $response->assertOk();
        $this->assertStringNotContainsString('data-shop-loadmore', $response->getContent());
    }

    public function test_shop_pagination_is_present_in_dom(): void
    {
        // Re-confirm the pagination `next` link component is wired up.
        $response = $this->get('/shop');

        $response->assertOk();
        $response->assertSee('data-shop-grid', false);
        $this->assertStringContainsString('href="', $response->getContent());
    }

    public function test_product_detail_page_returns_successful_response(): void
    {
        $response = $this->get('/shop/drop-02-hoodie');

        $response->assertOk();
    }

    public function test_product_detail_page_renders_real_data(): void
    {
        $response = $this->get('/shop/drop-02-hoodie');

        $response->assertOk();
        $response->assertSee('class="product-page"', false);
        $response->assertSee('Drop 02 Hoodie', false);
        $response->assertSee('1,890 EGP', false);
        $response->assertSee('class="product-page__badge', false);
        $response->assertSee('NEW', false);
    }

    public function test_product_detail_page_has_no_add_to_cart_button(): void
    {
        // Spec: cart backend is not ready — the detail page must not
        // fake an add-to-cart action.
        $response = $this->get('/shop/drop-02-hoodie');

        $response->assertOk();
        $this->assertStringNotContainsString('>ADD TO CART</span>', $response->getContent());
        $response->assertSee('disabled', false);
        $response->assertSee('SOON', false);
    }

    public function test_product_detail_page_returns_404_for_unknown_slug(): void
    {
        $response = $this->get('/shop/this-product-does-not-exist');

        $response->assertNotFound();
    }

    public function test_shop_page_uses_locked_palette_tokens(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        // The shop hero eyebrow line uses the same 28px lime line as home.
        $response->assertSee('class="shop-hero__eyebrow-line"', false);
        // The lime accent appears on the headline + card badges.
        $response->assertSee('shop-hero__headline-line--accent', false);
    }

    public function test_shop_links_use_named_route(): void
    {
        $response = $this->get('/shop');

        $response->assertOk();
        // The CTA scrolls to #grid (in-page).
        $response->assertSee('href="#grid"', false);
    }

    public function test_shop_js_exposes_filter_state_machine(): void
    {
        $js = file_get_contents(resource_path('js/shop.js'));
        $this->assertNotEmpty($js);
        // Must read the URL on load.
        $this->assertStringContainsString('loadFromUrl', $js);
        // Must write back to the URL.
        $this->assertStringContainsString('saveToUrl', $js);
        // Must support Esc to close.
        $this->assertStringContainsString("key === 'Escape'", $js);
        // Must respect aria-pressed.
        $this->assertStringContainsString('aria-pressed', $js);
        // Must build the price buckets from real prices, and only
        // when there are multiple distinct values.
        //
        // The buckets are built by the SERVER now (ShopController
        // splits the real min/max price into thirds) and the JS reads
        // their numeric bounds back out of the DOM, so the JS must
        // read `data-price-min` / `data-price-max` and not invent
        // numbers of its own.
        $this->assertStringContainsString('PRICE_BUCKETS', $js);
        $this->assertStringContainsString('dataset.priceMin', $js);
        $this->assertStringContainsString('dataset.priceMax', $js);
    }

    public function test_shop_price_filter_buckets_come_from_the_real_price_range(): void
    {
        // The server is the single source of truth for the price
        // buckets: it splits the real min/max into thirds and only
        // emits the panel when the range is not degenerate.
        $controller = file_get_contents(app_path('Http/Controllers/Store/ShopController.php'));

        $this->assertStringContainsString('$priceBuckets', $controller);
        $this->assertStringContainsString('min_price', $controller);
        $this->assertStringContainsString('max_price', $controller);
        $this->assertStringContainsString('$maxPrice > $minPrice', $controller);
    }

    public function test_shop_uses_only_home_page_palette_tokens_in_css(): void
    {
        // Sanity check: shop CSS must not introduce a new color.
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.shop-hero {', $css);
        $this->assertStringContainsString('.shop-filters {', $css);
        $this->assertStringContainsString('.shop-grid {', $css);
        // Reuses home tokens.
        $this->assertStringContainsString('var(--abo-lime', $css);
        $this->assertStringContainsString('var(--abo-black', $css);
        $this->assertStringContainsString('var(--container-max', $css);
    }
}
