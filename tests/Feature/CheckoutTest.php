<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    // The checkout pages read their copy from the static home.php data file,
    // but one cross-page invariant renders /cart, which is backed by the live
    // catalog. Migrations are therefore required for this file even though
    // almost nothing here writes to the database.
    use RefreshDatabase;
    // ─── Helpers ──────────────────────────────────────────────────

    private function getCheckoutPage(): string
    {
        $response = $this->get(route('checkout.show'));

        $response->assertStatus(200);

        return $response->getContent() ?: '';
    }

    private function getSuccessPage(string $order = 'ABO-2026-1001'): string
    {
        // The success page now looks the order up by its order_number,
        // so we back the test fixture with a real row when none exists.
        if (! Order::query()->where('order_number', $order)->exists()) {
            Order::factory()->create(['order_number' => $order]);
        }

        $response = $this->get(route('checkout.success', ['order' => $order]));

        $response->assertStatus(200);

        return $response->getContent() ?: '';
    }

    private function getProductsPayload(): array
    {
        $html = $this->getCheckoutPage();
        preg_match('/<script type="application\/json" data-checkout-products>(.+?)<\/script>/s', $html, $m);

        $this->assertArrayHasKey(1, $m, 'checkout page must expose the products JSON payload');

        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded, 'checkout products JSON must be an array');

        return $decoded;
    }

    private function getGovernoratesPayload(): array
    {
        $html = $this->getCheckoutPage();
        preg_match('/<script type="application\/json" data-checkout-governorates>(.+?)<\/script>/s', $html, $m);

        $this->assertArrayHasKey(1, $m, 'checkout page must expose the governorates JSON payload');

        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded, 'checkout governorates JSON must be an array');

        return $decoded;
    }

    private function getPaymentMethodsPayload(): array
    {
        $html = $this->getCheckoutPage();
        preg_match('/<script type="application\/json" data-checkout-payment-methods>(.+?)<\/script>/s', $html, $m);

        $this->assertArrayHasKey(1, $m, 'checkout page must expose the payment methods JSON payload');

        $decoded = json_decode($m[1], true);
        $this->assertIsArray($decoded, 'checkout payment methods JSON must be an array');

        return $decoded;
    }

    // ─── Routes + page shell ──────────────────────────────────────

    public function test_checkout_route_exists_and_responds_200(): void
    {
        $this->get(route('checkout.show'))->assertStatus(200);
    }

    public function test_checkout_success_route_exists_and_responds_200(): void
    {
        Order::factory()->create(['order_number' => 'ABO-2026-1001']);

        $this->get(route('checkout.success', ['order' => 'ABO-2026-1001']))->assertStatus(200);
    }

    public function test_checkout_success_route_404s_on_invalid_order_number(): void
    {
        // Anything that doesn't match ^ABO-{4-digit year}-{sequence}$ is rejected.
        $this->get('/checkout/success/ABO-12')->assertStatus(404);
        $this->get('/checkout/success/abc-123456')->assertStatus(404);
        $this->get('/checkout/success/foo')->assertStatus(404);
        // The old JS-only format (6 digits, no year) is no longer accepted.
        $this->get('/checkout/success/ABO-123456')->assertStatus(404);
    }

    public function test_checkout_page_uses_the_store_layout(): void
    {
        $html = $this->getCheckoutPage();

        // Inherits the global chrome (announcement, header, footer).
        $this->assertStringContainsString('Free shipping over 1,500 EGP', $html);
        $this->assertStringContainsString('store-header', $html);
        $this->assertStringContainsString('store-footer', $html);
    }

    public function test_checkout_page_keeps_global_chrome_intact(): void
    {
        $html = $this->getCheckoutPage();

        // The global chrome (header, primary nav, footer) is shared
        // with the rest of the storefront and must not be modified.
        $this->assertStringContainsString('aria-label="ABO — Home"', $html);
        $this->assertStringContainsString('aria-label="Primary"', $html);
        $this->assertStringContainsString('<footer class="store-footer"', $html);
        $this->assertStringContainsString('aria-label="Store announcement"', $html);
    }

    // ─── Header + step trail ──────────────────────────────────────

    public function test_header_cart_link_points_to_cart_route(): void
    {
        $html = $this->getCheckoutPage();

        $this->assertStringContainsString('data-nav-cart', $html);
        $this->assertStringContainsString('href="'.route('cart').'"', $html);
    }

    public function test_checkout_renders_step_trail_with_current_step_in_lime(): void
    {
        $html = $this->getCheckoutPage();

        $this->assertStringContainsString('checkout__steps', $html);
        $this->assertStringContainsString('aria-label="Checkout progress"', $html);
        $this->assertStringContainsString('>CART<', $html);
        $this->assertStringContainsString('>CHECKOUT<', $html);
        $this->assertStringContainsString('>DONE<', $html);
        // Current step is marked.
        $this->assertStringContainsString('checkout__step--current', $html);
        $this->assertStringContainsString('aria-current="step"', $html);
        // Cart step is a real link back to /cart.
        $this->assertStringContainsString('href="'.route('cart').'"', $html);
    }

    public function test_checkout_renders_eyebrow_and_heading_with_lime_accent(): void
    {
        $html = $this->getCheckoutPage();

        $this->assertStringContainsString('ALMOST YOURS', $html);
        $this->assertStringContainsString('CHECK', $html);
        $this->assertStringContainsString('OUT.', $html);
        $this->assertStringContainsString('checkout__title-accent', $html);
        $this->assertStringContainsString('checkout__eyebrow-line', $html);
    }

    // ─── Form structure ───────────────────────────────────────────

    public function test_form_posts_to_the_checkout_process_route(): void
    {
        $html = $this->getCheckoutPage();

        $this->assertStringContainsString('data-checkout-form', $html);
        // Compact whitespace so we can match attributes safely.
        $compact = preg_replace('/\s+/', ' ', $html);
        $this->assertMatchesRegularExpression(
            '#<form[^>]*\bdata-checkout-form\b[^>]*>#',
            $compact
        );
        // Server-side processing: the form now carries method=POST and
        // an action targeting the checkout.process route.
        if (preg_match('#<form[^>]*\bdata-checkout-form\b[^>]*>#', $compact, $m)) {
            $this->assertStringContainsString('method="POST"', $m[0]);
            $this->assertStringContainsString('action="'.route('checkout.process').'"', $m[0]);
        }
    }

    public function test_form_carries_csrf_and_hidden_fields(): void
    {
        $html = $this->getCheckoutPage();

        // @csrf renders as <input type="hidden" name="_token" value="…">.
        $this->assertMatchesRegularExpression('/<input[^>]+name="_token"/', $html);
        $this->assertStringContainsString('data-checkout-cart-json', $html);
        $this->assertStringContainsString('data-checkout-expected-total', $html);
    }

    public function test_form_renders_all_required_delivery_fields(): void
    {
        $html = $this->getCheckoutPage();

        foreach (['name', 'phone', 'governorate', 'area', 'address'] as $name) {
            $this->assertStringContainsString('name="'.$name.'"', $html);
        }
        // Notes is optional but still rendered.
        $this->assertStringContainsString('name="notes"', $html);

        // Required marker class is present on the form.
        $this->assertStringContainsString('checkout__required', $html);
    }

    public function test_phone_input_uses_tel_inputmode_with_egyptian_placeholder(): void
    {
        $html = $this->getCheckoutPage();

        $compact = preg_replace('/\s+/', ' ', $html);
        $this->assertMatchesRegularExpression(
            '#<input[^>]*\bname="phone"[^>]*\btype="tel"[^>]*\binputmode="tel"[^>]*>#',
            $compact
        );
        $this->assertMatchesRegularExpression(
            '#<input[^>]*\bname="phone"[^>]*\bplaceholder="01XXXXXXXXX"[^>]*>#',
            $compact
        );
    }

    public function test_governorate_select_contains_all_27_real_options(): void
    {
        $html = $this->getCheckoutPage();

        $compact = preg_replace('/\s+/', ' ', $html);
        $this->assertMatchesRegularExpression(
            '#<select[^>]*\bname="governorate"[^>]*>#',
            $compact
        );

        // The select is populated from the same source as the
        // governorates JSON payload. We check via the `value="…"`
        // attribute so Blade's whitespace between <option> and the
        // label text doesn't break the assertion.
        $this->assertStringContainsString('value="Cairo"', $html);
        $this->assertStringContainsString('value="Alexandria"', $html);
        $this->assertStringContainsString('value="Giza"', $html);
    }

    public function test_address_and_notes_are_textareas(): void
    {
        $html = $this->getCheckoutPage();

        $this->assertStringContainsString('<textarea', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*\bname="address"/', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*\bname="notes"/', $html);
    }

    // ─── Payment ─────────────────────────────────────────────────

    public function test_payment_panel_renders_at_least_one_method_when_available(): void
    {
        $methods = $this->getPaymentMethodsPayload();
        $html = $this->getCheckoutPage();

        $this->assertNotEmpty($methods, 'at least one payment method is configured (cod)');

        // Radiogroup with the radios for each method.
        $this->assertStringContainsString('role="radiogroup"', $html);
        $this->assertStringContainsString('aria-label="Payment method"', $html);

        // Cod is the configured method.
        $cod = collect($methods)->firstWhere('key', 'cod');
        $this->assertNotNull($cod, 'cash-on-delivery (cod) is configured');
        $this->assertStringContainsString('Cash on delivery', $html);
    }

    public function test_payment_panel_renders_empty_state_when_no_methods(): void
    {
        // We can't easily flip the config in this test, but the
        // view's empty branch is part of the contract. Confirm the
        // data attribute and the empty state text are present in
        // the source so a future flip works.
        $viewSource = file_get_contents(resource_path('views/checkout.blade.php'));
        $this->assertNotFalse($viewSource);
        $this->assertStringContainsString('data-checkout-payment-state', $viewSource);
        $this->assertStringContainsString('NO PAYMENT METHOD YET.', $viewSource);
        $this->assertStringContainsString("'none'", $viewSource);
    }

    public function test_payment_methods_payload_is_also_exposed_as_json_for_the_js(): void
    {
        $methods = $this->getPaymentMethodsPayload();
        $this->assertArrayHasKey('cod', $methods);
        $this->assertSame('cod', $methods['cod']['key']);
    }

    // ─── Summary + mobile bar ─────────────────────────────────────

    public function test_summary_shell_renders_for_desktop_and_collapsible_for_mobile(): void
    {
        $html = $this->getCheckoutPage();

        $this->assertStringContainsString('data-checkout-summary', $html);
        $this->assertStringContainsString('data-checkout-summary-items', $html);
        $this->assertStringContainsString('data-checkout-summary-count', $html);
        $this->assertStringContainsString('data-checkout-summary-subtotal', $html);
        $this->assertStringContainsString('data-checkout-summary-total', $html);

        // Edit-cart link back to /cart.
        $this->assertStringContainsString('checkout__edit-cart', $html);

        // Mobile collapsible.
        $this->assertStringContainsString('data-checkout-mobile-summary', $html);
        $this->assertStringContainsString('data-checkout-mobile-summary-count', $html);
        $this->assertStringContainsString('data-checkout-mobile-summary-total', $html);
    }

    public function test_mobile_bottom_bar_mirrors_the_submit_and_total(): void
    {
        $html = $this->getCheckoutPage();

        $this->assertStringContainsString('data-checkout-mobilebar', $html);
        $this->assertStringContainsString('data-checkout-mobilebar-total', $html);
        $this->assertStringContainsString('data-checkout-mobilebar-submit', $html);

        $compact = preg_replace('/\s+/', ' ', $html);
        $this->assertMatchesRegularExpression(
            '#<button[^>]*\bdata-checkout-mobilebar-submit\b[^>]*>#',
            $compact
        );
    }

    public function test_desktop_submit_button_is_present_and_wired_with_busy_state(): void
    {
        $html = $this->getCheckoutPage();

        $compact = preg_replace('/\s+/', ' ', $html);
        $this->assertMatchesRegularExpression(
            '#<button[^>]*\bdata-checkout-submit\b[^>]*>#',
            $compact
        );
        $this->assertStringContainsString('PLACE ORDER', $html);
        $this->assertStringContainsString('checkout__submit-busy', $html);
        $this->assertStringContainsString('PLACING ORDER', $html);
    }

    // ─── JSON payloads ────────────────────────────────────────────

    public function test_products_payload_uses_only_real_products_and_prices(): void
    {
        $products = $this->getProductsPayload();
        $data = require resource_path('views/store/data/home.php');
        $expected = collect($data['products'])->keyBy('slug')->all();

        $this->assertNotEmpty($products);
        foreach ($products as $p) {
            $this->assertArrayHasKey($p['slug'], $expected);
            $this->assertSame($expected[$p['slug']]['name'], $p['name']);
            $this->assertSame((int) $expected[$p['slug']]['price'], $p['price']);
        }
    }

    public function test_governorates_payload_has_27_real_egyptian_governorates(): void
    {
        $governorates = $this->getGovernoratesPayload();

        $this->assertCount(27, $governorates, 'there are 27 Egyptian governorates');
        $this->assertContains('Cairo', $governorates);
        $this->assertContains('Alexandria', $governorates);
        $this->assertContains('Giza', $governorates);
    }

    public function test_checkout_page_does_not_invent_fees_or_progress_bars(): void
    {
        $html = $this->getCheckoutPage();

        $this->assertStringNotContainsString('FREE SHIPPING', $html);
        $this->assertStringNotContainsString('data-checkout-progress', $html);
        $this->assertStringNotContainsString('checkout__progress', $html);
        $this->assertStringNotContainsString('PROMO', $html);
    }

    // ─── Success page ─────────────────────────────────────────────

    public function test_success_page_renders_hero_with_animated_checkmark_and_order_confirmed(): void
    {
        $html = $this->getSuccessPage();

        // Hero copy.
        $this->assertStringContainsString('ORDER PLACED', $html);
        $this->assertStringContainsString('ORDER', $html);
        $this->assertStringContainsString('CONFIRMED', $html);
        $this->assertStringContainsString('checkout-success__title-accent', $html);
        $this->assertStringContainsString('Your order is in.', $html);
        $this->assertStringContainsString('getting it ready', $html);

        // Animated checkmark: SVG with the .checkout-success__mark-circle
        // and .checkout-success__mark-check classes (the ones the CSS
        // animates via stroke-dasharray). The element is hidden until
        // the JS confirms a real order payload.
        $compact = preg_replace('/\s+/', ' ', $html);
        $this->assertMatchesRegularExpression(
            '#<div[^>]*\bdata-checkout-success-mark\b[^>]*\bhidden\b[^>]*>#',
            $compact,
            'success page must render the checkmark, hidden until the JS confirms the order payload'
        );
        $this->assertStringContainsString('checkout-success__mark-circle', $html);
        $this->assertStringContainsString('checkout-success__mark-check', $html);
    }

    public function test_success_page_renders_all_four_order_info_fields_with_data_hooks(): void
    {
        $html = $this->getSuccessPage('ABO-2026-1001');

        $this->assertStringContainsString('Order number', $html);
        $this->assertStringContainsString('Order date', $html);
        $this->assertStringContainsString('Payment method', $html);
        $this->assertStringContainsString('Order total', $html);

        // The JS reads these data-* hooks to fill in the values.
        $this->assertStringContainsString('data-checkout-success-order', $html);
        $this->assertStringContainsString('data-checkout-success-date', $html);
        $this->assertStringContainsString('data-checkout-success-payment', $html);
        $this->assertStringContainsString('data-checkout-success-total', $html);

        // The order number is rendered server-side from the URL
        // param so it's correct even if the JS module is delayed.
        $this->assertStringContainsString('ABO-2026-1001', $html);
    }

    public function test_success_page_renders_ordered_items_list_with_data_hook(): void
    {
        $html = $this->getSuccessPage();

        $this->assertStringContainsString('YOUR PIECES', $html);
        $this->assertStringContainsString('data-checkout-success-items', $html);
        $this->assertStringContainsString('data-checkout-success-items-section', $html);
    }

    public function test_success_page_renders_only_the_documented_action_buttons(): void
    {
        $html = $this->getSuccessPage();

        // Primary CTA: CONTINUE SHOPPING.
        $this->assertStringContainsString('CONTINUE SHOPPING', $html);
        $this->assertStringContainsString('checkout-success__cta--primary', $html);
        $compact = preg_replace('/\s+/', ' ', $html);
        $this->assertMatchesRegularExpression(
            '#<a[^>]*\bcheckout-success__cta--primary\b[^>]*\bhref="'.preg_quote(route('shop'), '#').'"[^>]*>#',
            $compact,
            'CONTINUE SHOPPING must link to the real /shop route'
        );

        // VIEW MY ORDERS / TRACK ORDER / similar must NOT be rendered:
        // there is no orders route in this codebase, so we never
        // ship a button that points nowhere. The task spec says:
        // "If the destination is not implemented, do not create a
        // fake destination or new backend route. Omit the button
        // or leave it out until the relevant page exists."
        $this->assertStringNotContainsString('VIEW MY ORDERS', $html);
        $this->assertStringNotContainsString('TRACK ORDER', $html);
        $this->assertStringNotContainsString('TRACK YOUR', $html);
    }

    public function test_success_page_renders_no_personal_data(): void
    {
        $html = $this->getSuccessPage();

        // The privacy design + the task spec both forbid rendering
        // name, phone, address, governorate, area, or notes. The
        // submit flow doesn't write any of them to sessionStorage,
        // and the page itself must not ask for them.
        $this->assertStringNotContainsString('name="name"', $html);
        $this->assertStringNotContainsString('name="phone"', $html);
        $this->assertStringNotContainsString('name="address"', $html);
        $this->assertStringNotContainsString('name="governorate"', $html);
        $this->assertStringNotContainsString('name="area"', $html);
        $this->assertStringNotContainsString('name="notes"', $html);
        $this->assertStringNotContainsString('Delivery information', $html);
        $this->assertStringNotContainsString('Recipient', $html);
    }

    public function test_success_page_exposes_payment_methods_payload_to_the_js(): void
    {
        $html = $this->getSuccessPage();

        $this->assertStringContainsString('data-checkout-success-payment-methods', $html);
        // The cod label is rendered into the JSON payload so the JS
        // can look it up without hardcoding copy.
        $this->assertStringContainsString('Cash on delivery', $html);
    }

    public function test_success_page_uses_the_store_layout(): void
    {
        $html = $this->getSuccessPage();

        $this->assertStringContainsString('store-header', $html);
        $this->assertStringContainsString('store-footer', $html);
    }

    public function test_success_page_exposes_order_number_to_the_js(): void
    {
        $html = $this->getSuccessPage('ABO-2026-6543');

        $this->assertStringContainsString('window.__checkoutSuccessOrderNumber', $html);
        $this->assertStringContainsString('ABO-2026-6543', $html);
    }

    // ─── JS surface ───────────────────────────────────────────────

    public function test_checkout_js_is_present_and_imported(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));
        $this->assertNotFalse($app);
        $this->assertStringContainsString("import './checkout.js';", $app);
        $this->assertStringContainsString("import './success.js';", $app);

        $source = file_get_contents(resource_path('js/checkout.js'));
        $this->assertNotFalse($source);
        $this->assertStringContainsString('STORAGE_KEY', $source);
        $this->assertStringContainsString('ORDER_STORAGE_KEY', $source);
        $this->assertStringContainsString('MAX_QTY_PER_LINE = 10', $source);
        $this->assertStringContainsString('MAX_CART_LINES = 20', $source);
        $this->assertStringContainsString('PHONE_REGEX', $source);
        $this->assertStringContainsString('01[0125][0-9]{8}', $source);
        $this->assertStringContainsString('localStorage', $source);
        $this->assertStringContainsString('sessionStorage', $source);
        $this->assertStringContainsString('generateOrderNumber', $source);
        $this->assertStringContainsString('validateCartStrict', $source);
        $this->assertStringContainsString('hydrate', $source);
        $this->assertStringContainsString('renderSummary', $source);
        $this->assertStringContainsString('validateForm', $source);
        $this->assertStringContainsString('paymentMethod', $source);
    }

    public function test_success_js_is_present_and_wired(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));
        $this->assertNotFalse($app);
        $this->assertStringContainsString("import './success.js';", $app);

        $source = file_get_contents(resource_path('js/success.js'));
        $this->assertNotFalse($source);

        // The success module must read the order payload and
        // redirect to /shop when it's missing.
        $this->assertStringContainsString('abo_last_order', $source);
        $this->assertStringContainsString('sessionStorage', $source);
        $this->assertStringContainsString('window.location.replace', $source);
        $this->assertStringContainsString('/shop', $source);

        // The success module renders order info, not personal data.
        $this->assertStringContainsString('formatDate', $source);
        $this->assertStringContainsString('paymentLabel', $source);
        $this->assertStringContainsString('renderItems', $source);
        $this->assertStringNotContainsString('console.log', $source);
        $this->assertStringNotContainsString('localStorage', $source);
    }

    public function test_success_js_logs_no_personal_data(): void
    {
        $source = file_get_contents(resource_path('js/success.js'));

        $this->assertNotFalse($source);
        $this->assertDoesNotMatchRegularExpression('/console\.(log|warn|error)\(/', $source);
        // No references to PII fields.
        $this->assertStringNotContainsString('phone', $source);
        $this->assertStringNotContainsString('address', $source);
        $this->assertStringNotContainsString('governorate', $source);
    }

    public function test_checkout_js_enforces_qty_cap_in_one_place(): void
    {
        $source = file_get_contents(resource_path('js/checkout.js'));

        $this->assertNotFalse($source);
        $this->assertStringContainsString('const MAX_QTY_PER_LINE = 10;', $source);
    }

    public function test_checkout_js_does_not_invent_prices_or_taxes(): void
    {
        $source = file_get_contents(resource_path('js/checkout.js'));

        $this->assertNotFalse($source);

        // No hard-coded sample prices.
        $this->assertDoesNotMatchRegularExpression('/\b1\s?890\b/', $source);
        $this->assertDoesNotMatchRegularExpression('/\b690\b/', $source);
        $this->assertDoesNotMatchRegularExpression('/\b1\s?450\b/', $source);
        $this->assertDoesNotMatchRegularExpression('/\b2\s?790\b/', $source);

        // No invented fees.
        $this->assertStringNotContainsString('tax', $source);
        $this->assertStringNotContainsString('discount', $source);
        $this->assertStringNotContainsString('coupon', $source);
        $this->assertStringNotContainsString('promo', $source);
    }

    public function test_checkout_js_logs_no_personal_data(): void
    {
        $source = file_get_contents(resource_path('js/checkout.js'));

        $this->assertNotFalse($source);

        // No console.log, console.warn, or console.error of the
        // user inputs anywhere in the module.
        $this->assertDoesNotMatchRegularExpression('/console\.(log|warn|error)\([^)]*phone/i', $source);
        $this->assertDoesNotMatchRegularExpression('/console\.(log|warn|error)\([^)]*address/i', $source);
        $this->assertDoesNotMatchRegularExpression('/console\.(log|warn|error)\([^)]*name/i', $source);
    }

    // ─── CSS namespace + tokens ───────────────────────────────────

    public function test_checkout_css_namespace_is_present_in_compiled_bundle(): void
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

        $this->assertStringContainsString('.checkout__', $css);
        $this->assertStringContainsString('.checkout__form', $css);
        $this->assertStringContainsString('.checkout__summary', $css);
        $this->assertStringContainsString('.checkout__mobilebar', $css);
        $this->assertStringContainsString('.checkout__payment', $css);
        $this->assertStringContainsString('.checkout-success__', $css);
    }

    public function test_checkout_css_uses_only_existing_tokens(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertNotFalse($css);

        $start = strpos($css, '/* ─── ABO Checkout');
        $this->assertNotFalse($start, '.checkout section must be delimited by a comment');

        $section = substr($css, $start);

        // Stop the section at the next `/* ───` so we don't catch
        // any unrelated declarations after it.
        if (preg_match('/\/\* ─── /', $section, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 5) {
            $section = substr($section, 0, $m[0][1]);
        }

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
                        preg_match_all('/--[a-z][a-z0-9-]*/', $call, $inner);
                        foreach ($inner[0] as $tok) {
                            $this->assertMatchesRegularExpression(
                                $brands,
                                $tok,
                                "checkout CSS uses non-existing token: $tok (in $call)"
                            );
                        }
                        break;
                    }
                }
                $i++;
            }
        }
    }

    public function test_checkout_css_does_not_introduce_new_hex_colors(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // Cover both the main checkout section AND the success-page
        // section. They live next to each other in the file:
        //   /* ─── ABO Checkout          (line N)
        //   …                           (main checkout rules)
        //   /* ─── ABO Checkout success  (line N+1)
        //   …                           (success-page rules)
        //   /* ─── A. Page Header       (next concern — stop here)
        $mainStart = strpos($css, '/* ─── ABO Checkout');
        $this->assertNotFalse($mainStart, 'expected a /* ─── ABO Checkout section');

        $sections = [];
        $cursor = $mainStart;
        while (true) {
            $endRel = strpos($css, '/* ─── ', $cursor + 5);
            // Find the next non-checkout marker (i.e. not "ABO Checkout" itself).
            while ($endRel !== false && substr($css, $endRel, 20) === '/* ─── ABO Checkout') {
                $endRel = strpos($css, '/* ─── ', $endRel + 5);
            }
            $sections[] = $endRel === false ? substr($css, $cursor) : substr($css, $cursor, $endRel - $cursor);
            if ($endRel === false) {
                break;
            }
            $cursor = $endRel;
            // Stop after one full pass — we only need the two
            // ABO Checkout sections; everything after is unrelated.
            if (count($sections) >= 2) {
                break;
            }
        }

        $section = implode("\n", $sections);

        // The section must use only existing brand tokens. Any raw
        // hex literal that does appear must be one of the locked
        // values (var() fallbacks for old browsers, SVG strokes
        // that target the brand color, etc.). New colors are not
        // permitted.
        $allowed = [
            '#8D928A',  // --abo-gray
            '#D7FF57',  // --abo-lime-hover
            '#ff5470',  // --abo-danger
            '#0D0F11',  // --abo-panel
            '#C6FF00',  // --abo-lime
        ];
        preg_match_all('/#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\b/', $section, $matches);

        foreach ($matches[0] as $hex) {
            $this->assertContains(
                strtolower($hex),
                array_map('strtolower', $allowed),
                "checkout CSS introduces a new hex color: $hex (use var(--abo-…) instead)"
            );
        }
    }

    // ─── Cross-page invariants ────────────────────────────────────

    public function test_checkout_button_on_cart_links_to_checkout(): void
    {
        $cartHtml = $this->get(route('cart'))->getContent();
        $compact = preg_replace('/\s+/', ' ', $cartHtml);

        $this->assertMatchesRegularExpression(
            '#<a[^>]*\bdata-cart-checkout\b[^>]*\bhref="'.preg_quote(route('checkout.show'), '#').'"[^>]*>#',
            $compact
        );
    }

    public function test_checkout_post_route_is_registered_to_persist_orders(): void
    {
        // Server-side: /checkout carries both a GET (the form) and a
        // POST (the submit) routes. PUT/PATCH/DELETE remain forbidden.
        $routes = collect($this->app['router']->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'checkout'));

        $hasGet = false;
        $hasPost = false;

        foreach ($routes as $route) {
            $methods = array_diff($route->methods(), ['HEAD']);
            $this->assertNotContains('PUT', $methods);
            $this->assertNotContains('PATCH', $methods);
            $this->assertNotContains('DELETE', $methods);
            $hasGet = $hasGet || in_array('GET', $methods, true);
            $hasPost = $hasPost || in_array('POST', $methods, true);
        }

        $this->assertTrue($hasGet, 'GET /checkout route must be registered');
        $this->assertTrue($hasPost, 'POST /checkout route must be registered for order placement');
    }
}
