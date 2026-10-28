<?php

namespace Tests\Feature\Store;

use App\Models\Banner;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Page;
use App\Models\Product;
use App\Models\ShippingSetting;
use App\Models\StoreSetting;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The storefront's chrome, measured.
 *
 * The announcement bar, the hero's figures, the footer's columns and
 * the store's contact details used to be a hand-written array in
 * resources/views/store/data/home.php. They claimed things the store had
 * not said: "120+ Designs" while the catalogue held four products,
 * "Free shipping over 1,500 EGP" whatever the shipping settings said,
 * a Follow column whose three links went nowhere.
 *
 * These tests pin down the truth they tell now: every figure comes from
 * the table that holds it, and a store that has filled nothing in gets
 * nothing rendered — no invented line, no dead link.
 */
class StorefrontChromeTest extends TestCase
{
    use RefreshDatabase;

    // ─── The announcement bar ────────────────────────────────────

    public function test_the_announcement_bar_carries_the_message_the_admin_published(): void
    {
        Banner::create([
            'title' => 'Winter drop — 10% off the whole store',
            'placement' => 'announcement',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('aria-label="Store announcement"', false);
        $response->assertSee('Winter drop — 10% off the whole store', false);
    }

    public function test_the_announcement_bar_waits_for_the_banner_to_go_live(): void
    {
        Banner::create([
            'title' => 'Tomorrow only',
            'placement' => 'announcement',
            'is_active' => true,
            'starts_at' => now()->addDay()->toDateString(),
            'sort_order' => 1,
        ]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('Tomorrow only', false);
        $response->assertDontSee('aria-label="Store announcement"', false);
    }

    public function test_the_announcement_bar_falls_back_to_the_free_shipping_threshold(): void
    {
        ShippingSetting::set('free_shipping_enabled', '1');
        ShippingSetting::set('free_shipping_threshold', '1500');

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('aria-label="Store announcement"', false);
        $response->assertSee('Free shipping over 1,500 EGP', false);
    }

    public function test_the_announcement_bar_is_absent_when_the_store_has_nothing_to_say(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('aria-label="Store announcement"', false);
    }

    // ─── The hero's figures ──────────────────────────────────────

    public function test_the_hero_figures_count_the_live_catalogue(): void
    {
        $categories = Category::factory()->count(3)->create();
        Product::factory()->count(5)->create([
            'category_id' => $categories->first()->id,
        ]);

        $response = $this->get(route('home'));

        $response->assertOk();

        $stats = $this->heroStats($response->getContent() ?: '');

        // Five designs, three categories — and no third figure, because
        // the store has not said what delivery costs.
        $this->assertSame(['5+', '3'], array_column($stats, 'value'));
        $this->assertSame(['DESIGNS', 'CATEGORIES'], array_column($stats, 'label'));
    }

    public function test_an_archived_product_is_not_counted_as_a_design(): void
    {
        Product::factory()->count(2)->create();
        Product::factory()->archived()->create();

        $response = $this->get(route('home'));

        $response->assertOk();

        $stats = $this->heroStats($response->getContent() ?: '');

        $this->assertSame('2+', $stats[0]['value']);
    }

    public function test_the_third_hero_figure_is_the_delivery_fee_when_free_shipping_is_off(): void
    {
        ShippingSetting::set('free_shipping_enabled', '0');
        ShippingSetting::set('default_fee', '50');

        $response = $this->get(route('home'));

        $response->assertOk();

        $stats = $this->heroStats($response->getContent() ?: '');

        $this->assertSame('50 EGP', $stats[2]['value']);
        $this->assertSame('DELIVERY', $stats[2]['label']);
    }

    // ─── The footer ──────────────────────────────────────────────

    public function test_the_footer_shop_column_links_to_the_published_categories(): void
    {
        $hoodies = Category::factory()->create(['name' => 'Hoodies', 'slug' => 'hoodies']);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee(route('shop', ['category' => $hoodies->slug]), false);
        $response->assertSee('>Shop</h2>', false);
    }

    public function test_the_footer_leaves_out_a_category_the_store_has_hidden(): void
    {
        Category::factory()->create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'is_active' => true,
            'is_visible' => false,
        ]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee(route('shop', ['category' => 'hoodies']), false);
    }

    public function test_the_footer_help_column_lists_the_published_pages(): void
    {
        Page::create(['slug' => 'about', 'title' => 'About ABO', 'is_active' => true]);
        Page::create(['slug' => 'shipping', 'title' => 'Shipping', 'is_active' => true]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('>Help</h2>', false);
        $response->assertSee(route('about'), false);
        $response->assertSee(route('shipping'), false);
    }

    public function test_the_footer_leaves_out_a_page_that_is_not_published(): void
    {
        Page::create(['slug' => 'about', 'title' => 'About ABO', 'is_active' => false]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('>Help</h2>', false);
    }

    public function test_the_footer_follow_column_lists_the_accounts_the_store_has_given(): void
    {
        StoreSetting::set('store.social', [
            'instagram' => 'instagram.com/abo',
            'facebook' => '',
        ]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('>Follow</h2>', false);
        $response->assertSee('https://instagram.com/abo', false);
    }

    public function test_the_footer_follow_column_is_left_out_when_no_account_was_given(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('>Follow</h2>', false);
    }

    public function test_the_footer_shows_the_stores_contact_details(): void
    {
        StoreSetting::set('store.phone', '01001234567');
        StoreSetting::set('store.whatsapp', '01001234567');
        StoreSetting::set('store.email', 'hello@abo.test');
        StoreSetting::set('store.address', '12 Talaat Harb Street, Cairo');

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('store-footer__contact', false);
        $response->assertSee('tel:01001234567', false);
        $response->assertSee('https://wa.me/01001234567', false);
        $response->assertSee('mailto:hello@abo.test', false);
        $response->assertSee('12 Talaat Harb Street, Cairo', false);
    }

    public function test_the_footer_leaves_out_the_contact_block_when_the_store_gave_no_details(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('store-footer__contact', false);
    }

    // ─── The cart's minimum order ────────────────────────────────

    public function test_the_cart_publishes_the_shops_minimum_order(): void
    {
        StoreSetting::set('orders.minimum', 750);

        $response = $this->get(route('cart'));

        $response->assertOk();
        $response->assertSee('data-cart-rules', false);
        $response->assertSee('"minimum_order":750', false);
    }

    public function test_the_cart_summary_carries_the_minimum_order(): void
    {
        StoreSetting::set('orders.minimum', 750);

        $response = $this->getJson(route('cart.summary'));

        $response->assertOk();
        $response->assertJsonPath('minimum_order', 750);

        // An empty cart is below any minimum the shop sets.
        $response->assertJsonPath('meets_minimum', false);
        $response->assertJsonPath('remaining_for_minimum', 750);
    }

    public function test_a_cart_that_clears_the_minimum_order_meets_it(): void
    {
        StoreSetting::set('orders.minimum', 750);

        $customer = Customer::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'price' => 900,
            'quantity' => 10,
        ]);

        CartItem::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($customer)->getJson(route('cart.summary'));

        $response->assertOk();
        $response->assertJsonPath('minimum_order', 750);
        $response->assertJsonPath('subtotal', 900);
        $response->assertJsonPath('meets_minimum', true);
        $response->assertJsonPath('remaining_for_minimum', 0);
    }

    public function test_a_shop_with_no_minimum_order_publishes_none(): void
    {
        $response = $this->get(route('cart'));

        $response->assertOk();
        $response->assertSee('"minimum_order":0', false);
    }

    // ─── The checkout's governorates ─────────────────────────────

    public function test_the_checkout_offers_only_the_governorates_in_the_table(): void
    {
        Governorate::factory()->create(['name' => 'Cairo']);
        Governorate::factory()->create(['name' => 'Alexandria']);

        $response = $this->get(route('checkout.show'));

        $response->assertOk();
        $response->assertSee('value="Cairo"', false);
        $response->assertSee('value="Alexandria"', false);

        // A governorate the store does not deliver to is not offered.
        $response->assertDontSee('value="Aswan"', false);
    }

    public function test_the_checkout_says_so_when_the_store_publishes_no_governates(): void
    {
        $response = $this->get(route('checkout.show'));

        $response->assertOk();
        $response->assertSee('We are not taking orders yet.', false);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * The hero's figures, as the page rendered them.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function heroStats(string $html): array
    {
        preg_match_all(
            '/<span class="hero__stat-value" data-home-stat-value>(.*?)<\/span>\s*<span class="hero__stat-label">(.*?)<\/span>/s',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            fn (array $match): array => [
                'value' => trim(html_entity_decode($match[1])),
                'label' => trim(html_entity_decode($match[2])),
            ],
            $matches,
        );
    }
}
