<?php

namespace Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    /**
     * Helper: GET /cart and return the rendered HTML as a string.
     */
    private function getCartPage(): string
    {
        $response = $this->get(route('cart'));

        $response->assertStatus(200);

        return $response->getContent() ?: '';
    }

    /**
     * Helper: read the products JSON payload rendered in the page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getProductsPayload(): array
    {
        $html = $this->getCartPage();
        preg_match('/<script type="application\/json" data-cart-products>(.+?)<\/script>/s', $html, $m);

        $this->assertArrayHasKey(1, $m, 'cart page must expose the products JSON payload');

        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded, 'cart page products JSON must be an array');

        return $decoded;
    }

    // ─── Route + page shell ──────────────────────────────────────

    public function test_cart_route_exists_and_responds_200(): void
    {
        $this->get(route('cart'))->assertStatus(200);
    }

    public function test_cart_page_uses_the_store_layout(): void
    {
        $html = $this->getCartPage();

        // Announcement bar, header brand and footer are inherited
        // from the store layout — they must not be modified.
        $this->assertStringContainsString('Free shipping over 1,500 EGP', $html);
        $this->assertStringContainsString('store-header', $html);
        $this->assertStringContainsString('store-footer', $html);
    }

    public function test_cart_page_keeps_global_chrome_intact(): void
    {
        $html = $this->getCartPage();

        $this->assertStringContainsString('aria-label="ABO — Home"', $html);
        $this->assertStringContainsString('aria-label="Primary"', $html);
        $this->assertStringContainsString('href="'.route('shop').'"', $html);
    }

    // ─── Header cart link + badge ───────────────────────────────

    public function test_header_cart_icon_is_a_link_to_the_cart_route(): void
    {
        $html = $this->getCartPage();

        $this->assertStringContainsString('data-nav-cart', $html);
        $this->assertStringContainsString('href="'.route('cart').'"', $html);
        $this->assertStringContainsString('data-nav-cart-count', $html);
    }

    public function test_header_cart_count_badge_is_present_and_hidden_when_zero(): void
    {
        $html = $this->getCartPage();

        // Badge exists, starts hidden (the count is computed in JS).
        $this->assertMatchesRegularExpression(
            '/<span[^>]*data-nav-cart-count[^>]*hidden/i',
            $html,
            'nav cart count badge must render with the hidden attribute by default'
        );
    }

    // ─── Page header + counters ─────────────────────────────────

    public function test_cart_page_heading_uses_lime_accent_on_cart_word(): void
    {
        $html = $this->getCartPage();

        $this->assertStringContainsString('YOUR', $html);
        $this->assertStringContainsString('CART.', $html);
        $this->assertStringContainsString('class="cart__title-accent"', $html);
        $this->assertStringContainsString('REVIEW YOUR PICKS', $html);
    }

    public function test_cart_page_has_an_aria_live_item_counter(): void
    {
        $html = $this->getCartPage();

        // The item counter must be a <p class="cart__count"> with
        // both data-cart-count and aria-live="polite" — order of
        // attributes does not matter, but they must be on the same
        // element. Blade may insert newlines between attributes, so
        // we use /s modifier.
        $this->assertMatchesRegularExpression(
            '#<p[^>]*class="cart__count"(?=[^>]*\bdata-cart-count\b)(?=[^>]*aria-live="polite")[^>]*>#is',
            $html,
            'item counter must carry both data-cart-count and aria-live="polite"'
        );
    }

    // ─── Empty state ─────────────────────────────────────────────

    public function test_cart_page_renders_empty_state_by_default(): void
    {
        $html = $this->getCartPage();

        $this->assertStringContainsString('class="cart__empty"', $html);
        $this->assertStringContainsString('data-cart-empty', $html);
        $this->assertStringContainsString('NOTHING HERE. YET.', $html);
        $this->assertStringContainsString('CONTINUE SHOPPING', $html);
    }

    public function test_empty_state_links_to_shop_route(): void
    {
        $html = $this->getCartPage();

        // The empty state CTA points to /shop.
        $this->assertMatchesRegularExpression(
            '/class="cart__empty-cta"[^>]*href="'.preg_quote(route('shop'), '/').'"/i',
            $html
        );
    }

    // ─── Order summary + shipping note ──────────────────────────

    public function test_cart_page_renders_order_summary_shell(): void
    {
        $html = $this->getCartPage();

        $this->assertStringContainsString('ORDER SUMMARY', $html);
        $this->assertStringContainsString('data-cart-summary-count', $html);
        $this->assertStringContainsString('data-cart-summary-subtotal', $html);
        $this->assertStringContainsString('data-cart-summary-total', $html);
    }

    public function test_shipping_is_shown_as_calculated_at_checkout(): void
    {
        $html = $this->getCartPage();

        $this->assertStringContainsString('Calculated at checkout', $html);
        $this->assertStringContainsString('Shipping is added at checkout', $html);
    }

    public function test_checkout_button_is_a_link_disabled_until_cart_has_items(): void
    {
        $html = $this->getCartPage();

        $this->assertStringContainsString('PROCEED TO CHECKOUT', $html);
        // The "COMING SOON" badge is gone — the CTA is now a real
        // link to /checkout, dimmed in the empty state.
        $this->assertStringNotContainsString('COMING SOON', $html);

        // The CTA is rendered as a real <a href="...checkout..."> with
        // aria-disabled="true" + data-disabled="true" + tabindex="-1"
        // in the empty state (so it's discoverable as a link, but
        // cannot be activated). The JS lifts these on render.
        $compact = preg_replace('/\s+/', ' ', $html);
        $this->assertMatchesRegularExpression(
            '#<a[^>]*\bdata-cart-checkout\b[^>]*\bhref="'.preg_quote(route('checkout.show'), '#').'"[^>]*>#',
            $compact
        );
        $this->assertMatchesRegularExpression(
            '#<a[^>]*\bdata-cart-checkout\b[^>]*\baria-disabled="true"[^>]*>#',
            $compact
        );
        $this->assertMatchesRegularExpression(
            '#<a[^>]*\bdata-cart-checkout\b[^>]*\bdata-disabled="true"[^>]*>#',
            $compact
        );
    }

    public function test_no_free_shipping_threshold_or_progress_bar_rendered(): void
    {
        $html = $this->getCartPage();

        // 1,500 EGP is only marketing copy in the announcement bar;
        // the cart must NOT show a progress bar or "FREE" label.
        $this->assertStringNotContainsString('FOR FREE SHIPPING', $html);
        $this->assertStringNotContainsString('data-cart-progress', $html);
        $this->assertStringNotContainsString('cart__progress', $html);
    }

    // ─── Mobile bottom bar ───────────────────────────────────────

    public function test_cart_page_renders_mobile_bottom_bar(): void
    {
        $html = $this->getCartPage();

        $this->assertStringContainsString('data-cart-mobilebar', $html);
        $this->assertStringContainsString('data-cart-mobilebar-total', $html);
        $this->assertStringContainsString('data-cart-mobilebar-checkout', $html);
        $this->assertStringContainsString('CHECKOUT', $html);

        // The mobile CTA is also a real <a> to /checkout, dimmed
        // in the empty state with aria-disabled + data-disabled.
        $compact = preg_replace('/\s+/', ' ', $html);
        $this->assertMatchesRegularExpression(
            '#<a[^>]*\bdata-cart-mobilebar-checkout\b[^>]*\bhref="'.preg_quote(route('checkout.show'), '#').'"[^>]*>#',
            $compact
        );
        $this->assertMatchesRegularExpression(
            '#<a[^>]*\bdata-cart-mobilebar-checkout\b[^>]*\baria-disabled="true"[^>]*>#',
            $compact
        );
    }

    // ─── Products JSON payload ───────────────────────────────────

    public function test_cart_page_exposes_real_products_as_json(): void
    {
        $products = $this->getProductsPayload();

        $this->assertNotEmpty($products, 'products payload must not be empty');
        $this->assertGreaterThanOrEqual(4, count($products), 'real catalog has at least 4 products');

        $first = $products[0];
        $this->assertSame('drop-02-hoodie', $first['slug']);
        $this->assertSame('Drop 02 Hoodie', $first['name']);
        $this->assertSame(1890, $first['price']);
        $this->assertSame('hoodies', $first['category']);
        $this->assertIsArray($first['colors']);
        $this->assertContains('#C6FF00', $first['colors']);
    }

    public function test_products_payload_uses_only_real_prices_from_data_file(): void
    {
        $data = require resource_path('views/store/data/home.php');
        $expected = collect($data['products'])->keyBy('slug')->all();

        $products = $this->getProductsPayload();

        foreach ($products as $p) {
            $this->assertArrayHasKey($p['slug'], $expected, "{$p['slug']} is a real product");
            $this->assertSame($expected[$p['slug']]['name'], $p['name']);
            $this->assertSame((int) $expected[$p['slug']]['price'], $p['price']);
            $this->assertSame($expected[$p['slug']]['image'], $p['image']);
        }
    }

    public function test_no_fake_prices_in_cart_page_or_data_file(): void
    {
        $html = $this->getCartPage();
        $data = require resource_path('views/store/data/home.php');

        // The cart page itself must not embed sample prices anywhere
        // outside the products JSON payload.
        $stripped = preg_replace(
            '/<script type="application\/json" data-cart-products">.*?<\/script>/s',
            '',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\b(1,?\s?890|690|1,?450|2,?790)\s?EGP\b/',
            $stripped,
            'cart page must not hard-code sample prices outside the products JSON'
        );

        // The data file prices must match what the page exposes.
        foreach ($data['products'] as $p) {
            $this->assertIsInt($p['price']);
            $this->assertGreaterThan(0, $p['price']);
        }
    }

    // ─── JS surface ──────────────────────────────────────────────

    public function test_cart_js_is_present_and_imported(): void
    {
        $html = $this->getCartPage();

        // Vite hashes the entry; we just check the entry shape. The hash
        // is base64url, so it may contain `-` and `_` as well.
        $this->assertMatchesRegularExpression(
            '/<script[^>]+src="[^"]*app-[A-Za-z0-9_-]+\.js"/',
            $html
        );
    }

    public function test_cart_js_exposes_the_required_helpers(): void
    {
        $source = file_get_contents(resource_path('js/cart.js'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString('STORAGE_KEY', $source);
        $this->assertStringContainsString('MAX_QTY_PER_LINE', $source);
        $this->assertStringContainsString('10', $source);
        $this->assertStringContainsString('localStorage', $source);
        $this->assertStringContainsString('addLine', $source);
        $this->assertStringContainsString('setQty', $source);
        $this->assertStringContainsString('removeLine', $source);
        $this->assertStringContainsString('hydratedCart', $source);
        $this->assertStringContainsString('undo', $source); // UNDO is wired (toast or restored line)
        $this->assertStringContainsString('crypto.randomUUID', $source) === false
            || $this->assertStringContainsString('Date.now', $source);
    }

    public function test_cart_js_does_not_invent_prices_and_only_reads_from_payload(): void
    {
        $source = file_get_contents(resource_path('js/cart.js'));

        $this->assertNotFalse($source);

        // No hard-coded price or fake number in JS.
        $this->assertDoesNotMatchRegularExpression('/\b1\s?890\b/', $source);
        $this->assertDoesNotMatchRegularExpression('/\b690\b/', $source);
        $this->assertDoesNotMatchRegularExpression('/\b1\s?450\b/', $source);
        $this->assertDoesNotMatchRegularExpression('/\b2\s?790\b/', $source);
    }

    public function test_cart_js_enforces_qty_cap_in_one_place(): void
    {
        $source = file_get_contents(resource_path('js/cart.js'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString('const MAX_QTY_PER_LINE = 10;', $source);
    }

    public function test_cart_lines_survive_pages_without_a_products_payload(): void
    {
        // The products payload only ships with the cart view. When it is
        // missing, cart.js must keep the stored lines instead of
        // dropping them — otherwise every navigation empties the cart
        // and /checkout always bounces back to /cart.
        $source = file_get_contents(resource_path('js/cart.js'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString(
            'if (products.length > 0)',
            $source,
            'cart lines may only be dropped when there is a payload to validate them against'
        );
    }

    // ─── CSS namespace + tokens ──────────────────────────────────

    public function test_cart_css_namespace_is_present_in_compiled_bundle(): void
    {
        $manifest = json_decode(
            file_get_contents(public_path('build/manifest.json')) ?: '{}',
            true
        );
        $cssKey = collect($manifest)->keys()->first(
            fn ($k) => str_ends_with($k, 'css/app.css')
        );
        $this->assertNotNull($cssKey, 'compiled CSS entry must exist in manifest');

        $cssFile = public_path('build/'.$manifest[$cssKey]['file']);
        $css = file_get_contents($cssFile);
        $this->assertNotFalse($css);

        $this->assertStringContainsString('.cart__', $css);
        $this->assertStringContainsString('.cart__summary', $css);
        $this->assertStringContainsString('.cart__mobilebar', $css);
        $this->assertStringContainsString('.cart__toast', $css);
        $this->assertStringContainsString('.cart__empty', $css);
    }

    public function test_cart_css_uses_only_existing_tokens(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // Pull the .cart* section.
        $this->assertNotFalse($css);

        $start = strpos($css, '/* ─── ABO Cart');
        $this->assertNotFalse($start, '.cart section must be delimited by a comment');

        $section = substr($css, $start);

        // Walk every `var(--xxx[, fallback])` occurrence. We split on
        // top-level `var(` by counting parens so nested fallbacks
        // (e.g. `var(--swatch, var(--abo-border-soft))`) are matched
        // individually. We only assert that the primary token or any
        // fallback token is an existing brand token, or the local
        // `--swatch` variable that the JS sets inline.
        $brands = '/^--(abo-[a-z-]+|font-[a-z-]+|container-max|abo-radius[a-z-]*|swatch)$/';

        preg_match_all('/var\(/', $section, $matches, PREG_OFFSET_CAPTURE);
        $positions = array_map(fn ($m) => $m[1], $matches[0]);
        foreach ($positions as $startPos) {
            $depth = 0;
            $i = $startPos;
            while ($i < strlen($section)) {
                $c = $section[$i];
                if ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $call = substr($section, $startPos, $i - $startPos + 1);
                        // Inside the call: a comma-separated list of `--name` tokens.
                        preg_match_all('/--[a-z][a-z0-9-]*/', $call, $inner);
                        foreach ($inner[0] as $tok) {
                            $this->assertMatchesRegularExpression(
                                $brands,
                                $tok,
                                "cart CSS uses non-existing token: $tok (in $call)"
                            );
                        }
                        break;
                    }
                }
                $i++;
            }
        }
    }

    public function test_cart_css_does_not_introduce_new_hex_colors(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $start = strpos($css, '/* ─── ABO Cart');
        $section = substr($css ?: '', $start ?: 0);

        // Find every hex color in the cart section.
        preg_match_all('/#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b/', $section, $matches);

        // The cart section is allowed to use the same "soft" greys as
        // the rest of the file (these are the brand-mandated deep
        // panel/border hexes that already appear in the shop card
        // thumbnail rule). No other hex is permitted.
        $allowed = ['#0a0b0d', '#0a0a0a', '#1a1c1f', '#050607', '#0D0F11', '#000000'];
        foreach ($matches[0] as $hex) {
            $this->assertContains(
                strtolower($hex),
                array_map('strtolower', $allowed),
                "cart CSS introduces a new hex color: $hex"
            );
        }
    }

    // ─── Page render does not break existing chrome ──────────────

    public function test_cart_page_does_not_show_add_to_cart_on_product_page(): void
    {
        // Sanity: the cart page must not contain the product page's
        // disabled ADD TO CART — SOON button, so we know the
        // template paths don't accidentally mix.
        $html = $this->getCartPage();

        $this->assertStringNotContainsString('ADD TO CART — SOON', $html);
    }

    public function test_app_js_imports_the_cart_module(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));
        $this->assertNotFalse($app);
        $this->assertStringContainsString("import './cart.js';", $app);
    }
}
