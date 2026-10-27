<?php

namespace Tests\Feature;

use App\Models\Product;
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

    public function test_products_payload_uses_the_live_catalog_prices(): void
    {
        // The catalog lives in the database: the payload is priced from
        // the variant rows, not from a static file.
        $expected = Product::query()
            ->with(['variants', 'category'])
            ->get()
            ->keyBy('slug');

        $products = $this->getProductsPayload();

        $this->assertNotEmpty($products);

        foreach ($products as $p) {
            $this->assertTrue($expected->has($p['slug']), "{$p['slug']} is a real product");

            $product = $expected->get($p['slug']);

            $this->assertSame($product->name, $p['name']);
            $this->assertSame((int) $product->min_price, $p['price']);
            $this->assertSame($product->category?->slug, $p['category']);

            // The image is published as a full URL the browser can load
            // straight from `img.src`.
            $this->assertStringStartsWith('http', $p['image']);
            $this->assertStringEndsWith('streetwear-hero.jpg', $p['image']);
        }
    }

    public function test_no_fake_prices_in_cart_page_or_data_file(): void
    {
        $html = $this->getCartPage();
        $data = require resource_path('views/store/data/home.php');

        // The cart page itself must not embed sample prices anywhere
        // outside the products JSON payload.
        $stripped = preg_replace(
            '/<script type="application\/json" data-cart-products>.*?<\/script>/s',
            '',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\b(1,?\s?890|690|1,?450|2,?790)\s?EGP\b/',
            $stripped,
            'cart page must not hard-code sample prices outside the products JSON'
        );

        // The static data file carries no catalog, so no sample price
        // is left in it that could reach the page.
        $this->assertArrayNotHasKey('products', $data);
        $this->assertArrayNotHasKey('categories', $data);
    }

    // ─── JS surface ──────────────────────────────────────────────

    public function test_cart_js_is_present_and_imported(): void
    {
        $html = $this->getCartPage();

        // Vite hashes the entry; we just check the entry shape. The hash
        // alphabet is base64url, so it carries "-" and "_" as well.
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

        // The client no longer mints cart ids at all — the server assigns
        // cart_items.id — so there is no id helper left to depend on. What
        // must stay true is that nothing reaches for crypto.randomUUID:
        // it needs a secure context and is simply absent on a plain-http
        // storefront, where the cart would then break. Date.now() is still
        // used, for the image cache-bust.
        $this->assertStringNotContainsString('crypto.randomUUID', $source);
        $this->assertStringContainsString('Date.now', $source);
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

    // ─── The client talks to the server ──────────────────────────

    public function test_the_cart_client_calls_the_endpoints_the_server_exposes(): void
    {
        $cart = file_get_contents(resource_path('js/cart.js'));
        $client = file_get_contents(resource_path('js/cart-client.js'));

        $this->assertNotFalse($cart);
        $this->assertNotFalse($client);

        // The URLs the client hard-codes must be the ones routes/web.php
        // registers. A renamed route would otherwise break the cart in
        // the browser only — the server tests would still be green.
        // route(..., false) gives the path, which is what the client
        // puts in fetch().
        $this->assertStringContainsString("'".route('cart.summary', [], false)."'", $cart);
        $this->assertStringContainsString("'".route('cart.items.store', [], false)."'", $cart);

        // The per-line URLs are built from the collection URL, so they
        // stay in step with it.
        $this->assertStringContainsString('${ITEMS_URL}/${line.id}', $cart);
    }

    public function test_the_cart_client_sends_the_csrf_token_the_layout_publishes(): void
    {
        $client = file_get_contents(resource_path('js/cart-client.js'));
        $layout = file_get_contents(resource_path('views/layouts/store.blade.php'));

        $this->assertNotFalse($client);
        $this->assertNotFalse($layout);

        // The layout publishes the token...
        $this->assertStringContainsString('name="csrf-token"', $layout);

        // ...and the client sends it back the way the rest of the project
        // does.
        $this->assertStringContainsString('meta[name="csrf-token"]', $client);
        $this->assertStringContainsString('X-CSRF-TOKEN', $client);
    }

    public function test_the_cart_client_reports_a_failed_request_instead_of_throwing(): void
    {
        $client = file_get_contents(resource_path('js/cart-client.js'));

        $this->assertNotFalse($client);

        preg_match('/export async function apiRequest\(.*?\n}/s', $client, $m);
        $this->assertNotEmpty($m, 'cart-client.js must export an apiRequest function');

        // A failed request resolves with a reportable result. If it
        // threw instead, the caller would have to guess what happened and
        // the page could show a false success.
        $this->assertStringNotContainsString('throw', $m[0]);
        $this->assertStringContainsString('ok: false', $m[0]);
        $this->assertStringContainsString('status: 0', $m[0]);
    }

    public function test_the_cart_client_prefers_the_field_error_a_422_carries(): void
    {
        $client = file_get_contents(resource_path('js/cart-client.js'));

        $this->assertNotFalse($client);

        preg_match('/export function extractErrorMessage\(.*?\n}/s', $client, $m);
        $this->assertNotEmpty($m, 'cart-client.js must export an extractErrorMessage function');

        $body = $m[0];
        $errorsAt = strpos($body, 'data.errors');
        $messageAt = strpos($body, 'data.message');

        $this->assertNotFalse($errorsAt, 'the extractor must look at the field errors');
        $this->assertNotFalse($messageAt, 'the extractor must fall back to the generic message');
        $this->assertLessThan(
            $messageAt,
            $errorsAt,
            'the field error (e.g. "Only 3 units left in stock.") must win over the generic 422 message'
        );
    }

    public function test_the_cart_draws_what_the_server_said_and_keeps_localstorage_as_a_cache(): void
    {
        $cart = file_get_contents(resource_path('js/cart.js'));

        $this->assertNotFalse($cart);

        // The server's answer is what the page draws...
        $this->assertMatchesRegularExpression(
            '/state\.lines\s*=\s*\(summary\?\.items \|\| \[\]\)\.map\(lineFromServer\)/',
            $cart,
            'the cart lines must come from the server summary'
        );
        $this->assertMatchesRegularExpression(
            '/state\.subtotal\s*=\s*Number\(summary\?\.subtotal\)/',
            $cart,
            'the total must be the server figure, never one the client adds up'
        );

        // ...and the cache is written from it, so the two never drift.
        $this->assertStringContainsString('writeCache(state.lines);', $cart);
    }

    public function test_the_cart_adds_by_variant_id_and_not_by_a_guess(): void
    {
        $cart = file_get_contents(resource_path('js/cart.js'));

        $this->assertNotFalse($cart);

        // The cart is keyed by variant id, so that is what travels to the
        // server — a slug or a colour name would be a guess.
        $this->assertStringContainsString('variant_id: variantId', $cart);
    }

    public function test_the_undo_window_never_has_to_re_create_the_line(): void
    {
        $cart = file_get_contents(resource_path('js/cart.js'));

        $this->assertNotFalse($cart);

        // The server is only told once the undo window closes, so a line
        // the shopper puts back was never deleted in the first place.
        preg_match('/function restorePendingRemoval\(.*?\n}/s', $cart, $m);
        $this->assertNotEmpty($m, 'cart.js must keep a restorePendingRemoval helper');
        $this->assertStringNotContainsString('apiRequest', $m[0]);
        $this->assertStringNotContainsString('removeLine', $m[0]);

        // And the deferred delete is what actually happens.
        preg_match('/function softRemove\(.*?\n}/s', $cart, $m);
        $this->assertNotEmpty($m, 'cart.js must keep a softRemove helper');
        $this->assertStringContainsString('setTimeout', $m[0]);
        $this->assertStringContainsString('removeLine', $m[0]);
        $this->assertStringContainsString('UNDO', $cart);
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
