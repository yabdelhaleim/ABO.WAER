<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Page;
use App\Models\StoreSetting;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    public function test_home_page_returns_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertOk();
    }

    public function test_home_page_uses_storefront_layout(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<html lang="en"', false);
        $response->assertSee('class="abo-store-body"', false);
        $response->assertSee('<main class="abo-store-main"', false);
    }

    public function test_home_page_renders_cinematic_intro_overlay(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-abo-intro', false);
        $response->assertSee('abo-intro__veil', false);
        $response->assertSee('data-abo-intro-layer="left"', false);
        $response->assertSee('data-abo-intro-layer="right"', false);
        $response->assertSee('class="abo-intro__zipper"', false);
        $response->assertSee('data-abo-intro-puller', false);
        $response->assertSee('class="abo-intro__logo"', false);
        $response->assertSee('data-abo-intro-tagline', false);
        $response->assertSee('data-abo-intro-skip', false);
        $response->assertSee('class="abo-intro__tagline-char">N<', false);
        $response->assertSee('class="abo-intro__tagline-char">.</span>', false);
    }

    public function test_intro_overlay_has_skip_button_with_label(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('aria-label="Skip intro"', false);
        $response->assertSee('Skip', false);
    }

    public function test_intro_overlay_marks_aria_hidden_for_decorative_content(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('role="presentation"', false);
        $response->assertSee('aria-hidden="true"', false);
    }

    // ─── Section A: Announcement bar ──────────────────────────────

    public function test_home_page_renders_the_announcement_the_admin_published(): void
    {
        Banner::create([
            'title' => 'Drop 02 is live',
            'image_path' => 'banners/drop-02.jpg',
            'placement' => 'announcement',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="announcement"', false);
        $response->assertSee('Drop 02 is live', false);

        // The line the page used to carry is not the store's to repeat.
        $response->assertDontSee('Free shipping over 1,500 EGP', false);
    }

    public function test_home_page_has_no_announcement_bar_when_the_store_has_nothing_to_say(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('aria-label="Store announcement"', false);
    }

    // ─── Section B: Header ──────────────────────────────────────

    public function test_home_page_renders_main_navigation(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="store-header"', false);
        $response->assertSee('aria-label="Primary"', false);
        $response->assertSee('data-abo-mobile-menu-trigger', false);
        $response->assertSee('data-abo-mobile-menu', false);
    }

    // ─── Section C: Hero ─────────────────────────────────────────

    public function test_home_page_renders_hero_with_required_copy(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="hero"', false);
        $response->assertSee('data-home-hero', false);
        $response->assertSee('URBAN / STREETWEAR / LIFESTYLE', false);
        $response->assertSee('MODERN FIT.', false);
        $response->assertSee('URBAN VIBE.', false);
        $response->assertSee('Premium men&#039;s wear built for the next generation', false);
        $response->assertSee('SHOP NOW', false);
        $response->assertSee('EXPLORE CATEGORIES', false);
    }

    public function test_home_page_renders_measured_hero_stats(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $html = $response->getContent() ?: '';

        preg_match_all('/<span class="hero__stat-value" data-home-stat-value>(.*?)<\/span>/s', $html, $m);

        // The figures are counted from the catalogue the seeded fixture
        // holds — four designs under four categories — and a store with
        // no shipping settings configured gets no third figure invented
        // to fill the row.
        $this->assertSame(['4+', '4'], $m[1]);

        // The numbers the store had never been told are gone.
        $this->assertStringNotContainsString('120+', $html);
        $this->assertStringNotContainsString('14d', $html);
    }

    public function test_home_page_renders_hero_photo_with_dimensions(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-home-photo', false);
        $response->assertSee('ABO brown polo sweatshirt with embroidered DEFINE ABO logo', false);
        $response->assertSee('fetchpriority="high"', false);
        $response->assertSee('width="800"', false);
        $response->assertSee('height="450"', false);
    }

    public function test_home_page_renders_hero_lime_sweep(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="hero__sweep"', false);
        $response->assertSee('data-home-sweep', false);
    }

    // ─── Section D: Ticker ───────────────────────────────────────

    public function test_home_page_renders_ticker_band(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="ticker"', false);
        $response->assertSee('NOT MADE TO BLEND IN', false);
        $response->assertSee('BETTER THAN YESTERDAY', false);
        $response->assertSee('ticker__item', false);
    }

    // ─── Section E: Shop by category ─────────────────────────────

    public function test_home_page_renders_four_category_cards(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="categories"', false);
        $response->assertSee('class="cat-card"', false);
        $response->assertSee('>HOODIES</span>', false);
        $response->assertSee('>T-SHIRTS</span>', false);
        $response->assertSee('>PANTS</span>', false);
        $response->assertSee('>JACKETS</span>', false);
    }

    // ─── Section F: New drop ────────────────────────────────────

    public function test_home_page_renders_four_product_cards_with_prices(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="new"', false);
        $response->assertSee('class="product"', false);
        $response->assertSee('Drop 02 Hoodie', false);
        $response->assertSee('Urban Tee', false);
        $response->assertSee('Cargo Pant', false);
        $response->assertSee('Limo Bomber', false);
        $response->assertSee('1,890 EGP', false);
        $response->assertSee('EGP', false);
    }

    public function test_home_page_renders_product_badges(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="product__badge', false);
        $response->assertSee('NEW', false);
        $response->assertSee('BEST SELLER', false);
        $response->assertSee('LIMITED', false);
    }

    public function test_home_page_renders_add_to_cart_overlay(): void
    {
        // Cart is not implemented yet — the "+ ADD TO CART" overlay is
        // intentionally not rendered. The card link goes to the
        // product detail page instead (see next test).
        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('+ ADD TO CART', false);
    }

    public function test_new_drop_product_cards_link_to_product_pages(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('href="'.route('shop.show', ['slug' => 'drop-02-hoodie']).'"', false);
        $response->assertSee('href="'.route('shop.show', ['slug' => 'urban-tee-black']).'"', false);
        $response->assertSee('href="'.route('shop.show', ['slug' => 'cargo-pant-olive']).'"', false);
        $response->assertSee('href="'.route('shop.show', ['slug' => 'limo-bomber-lime']).'"', false);
    }

    // ─── Section G: Brand statement banner ───────────────────────

    public function test_home_page_renders_brand_statement_banner(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="banner"', false);
        $response->assertSee('BETTER THAN', false);
        $response->assertSee('YESTERDAY.', false);
        $response->assertSee('STREET ROOTS', false);
    }

    // ─── Section H: Why ABO ─────────────────────────────────────

    public function test_home_page_renders_why_grid_with_four_cells(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="why"', false);
        $response->assertSee('class="why__cell"', false);
        $response->assertSee('Premium fabric', false);
        $response->assertSee('Modern fit', false);
        $response->assertSee('Fast delivery', false);
        $response->assertSee('Easy returns', false);
        $response->assertSee('class="why__num">01<', false);
        $response->assertSee('class="why__num">04<', false);
    }

    // ─── Section I: Newsletter ──────────────────────────────────

    public function test_home_page_renders_newsletter_form(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="newsletter"', false);
        $response->assertSee('data-home-newsletter', false);
        $response->assertSee('Get first access to every drop', false);
        $response->assertSee('type="email"', false);
        $response->assertSee('>SUBSCRIBE</span>', false);
    }

    public function test_home_page_newsletter_has_success_message(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-home-newsletter-success', false);
        $response->assertSee('YOU&#039;RE IN', false);
    }

    // ─── Section J: Footer ──────────────────────────────────────

    public function test_home_page_renders_footer_with_the_columns_the_store_has(): void
    {
        // The footer's columns are measured: the Help column lists the
        // pages the store has published and the Follow column lists the
        // accounts it has given.
        Page::create(['slug' => 'about', 'title' => 'About ABO', 'is_active' => true]);
        Page::create(['slug' => 'shipping', 'title' => 'Shipping', 'is_active' => true]);
        StoreSetting::set('store.social', ['instagram' => 'instagram.com/abo']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<footer class="store-footer"', false);
        $response->assertSee('store-footer__brand', false);
        $response->assertSee('>Shop</h2>', false);
        $response->assertSee('>Help</h2>', false);
        $response->assertSee('>Follow</h2>', false);
        $response->assertSee('All rights reserved', false);

        // Every link points at the store's own rows.
        $response->assertSee(route('about'), false);
        $response->assertSee(route('shipping'), false);
        $response->assertSee('https://instagram.com/abo', false);
    }

    public function test_home_page_footer_drops_the_columns_the_store_has_not_filled(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        // No published pages and no social accounts: the Help and Follow
        // columns have nothing to say, so they are not rendered. A
        // column of links that go nowhere is worse than no column.
        $response->assertSee('>Shop</h2>', false);
        $response->assertDontSee('>Help</h2>', false);
        $response->assertDontSee('>Follow</h2>', false);
    }

    // ─── Meta & assets ──────────────────────────────────────────

    public function test_home_page_sets_dark_theme_color_meta(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('name="theme-color" content="#050607"', false);
    }

    public function test_home_page_meta_title_contains_brand(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<title>ABO — Modern fit, urban vibe.', false);
    }

    public function test_home_page_includes_open_graph_tags(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('property="og:title"', false);
        $response->assertSee('property="og:image"', false);
        $response->assertSee('name="twitter:card"', false);
    }

    public function test_home_page_links_intro_and_hero_to_bundled_assets(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('images/abo-logo.svg', false);
        $response->assertSee('images/streetwear-hero.jpg', false);
    }

    // ─── a11y / no-JS ────────────────────────────────────────────

    public function test_home_page_has_skip_to_content_link(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="skip-to-content"', false);
        $response->assertSee('href="#content"', false);
    }

    public function test_home_page_adds_js_class_inline(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        // The inline <script> in <head> is responsible for the `js` class
        // so the page is fully visible without JS.
        $response->assertSee('documentElement.classList.add(\'js\')', false);
    }

    public function test_home_js_listens_for_intro_reveal_event(): void
    {
        // home.js listens for `abo:intro-reveal` and dispatches
        // `abo:home-reveal` as a fallback when the intro is disabled.
        // We read the source so the test does not depend on the build.
        $homeJs = file_get_contents(resource_path('js/home.js'));
        $this->assertNotEmpty($homeJs);
        $this->assertStringContainsString("addEventListener('abo:intro-reveal'", $homeJs);
        $this->assertStringContainsString("dispatchEvent(new Event('abo:home-reveal'))", $homeJs);
    }
}
