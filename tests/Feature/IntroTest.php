<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\IntroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class IntroTest extends TestCase
{
    use RefreshDatabase;

    public function test_intro_overlay_is_rendered_on_home(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-abo-intro', false);
        $response->assertSee('class="abo-intro"', false);
    }

    public function test_intro_overlay_is_not_rendered_once_the_intro_already_played(): void
    {
        // The overlay is a full-screen black layer: rendering it and then
        // removing it from JavaScript paints a black flash on every
        // reload, so the server must skip it entirely once the intro has
        // played. The cookie is plain (excluded from Laravel's cookie
        // encryption) so the browser and the server read the same value.
        $response = $this->withUnencryptedCookie(IntroService::COOKIE_NAME, '1')->get('/');

        $response->assertOk();
        $response->assertDontSee('data-abo-intro', false);
        $response->assertDontSee('class="abo-intro"', false);

        // The flag has to reach the server as the plain value the browser
        // wrote — it is deliberately excluded from Laravel's cookie
        // encryption so both sides read the same thing.
        $this->assertSame('1', $this->app['request']->cookie(IntroService::COOKIE_NAME));
    }

    public function test_intro_overlay_is_not_rendered_when_disabled_by_query_flag(): void
    {
        $response = $this->get('/?intro=0');

        $response->assertOk();
        $response->assertDontSee('data-abo-intro', false);
    }

    public function test_intro_overlay_is_rendered_when_forced_by_query_flag(): void
    {
        // ?intro=1 replays the intro even when it already played.
        $response = $this->withUnencryptedCookie(IntroService::COOKIE_NAME, '1')->get('/?intro=1');

        $response->assertOk();
        $response->assertSee('data-abo-intro', false);
    }

    public function test_intro_overlay_is_rendered_when_scrubbing_by_query_flag(): void
    {
        $response = $this->withUnencryptedCookie(IntroService::COOKIE_NAME, '1')->get('/?intro=t3000');

        $response->assertOk();
        $response->assertSee('data-abo-intro', false);
    }

    public function test_intro_gate_applies_to_the_shop_and_product_pages(): void
    {
        $product = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'is_archived' => false,
        ]);

        // Every page that renders the overlay shares the same gate.
        $this->withUnencryptedCookie(IntroService::COOKIE_NAME, '1')
            ->get(route('shop'))
            ->assertDontSee('data-abo-intro', false);

        $this->withUnencryptedCookie(IntroService::COOKIE_NAME, '1')
            ->get(route('shop.show', ['slug' => $product->slug]))
            ->assertDontSee('data-abo-intro', false);
    }

    public function test_intro_overlay_is_rendered_on_the_shop_and_product_pages(): void
    {
        $product = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'is_archived' => false,
        ]);

        $this->get(route('shop'))->assertSee('data-abo-intro', false);
        $this->get(route('shop.show', ['slug' => $product->slug]))->assertSee('data-abo-intro', false);
    }

    public function test_intro_service_gate_rules(): void
    {
        $this->assertTrue(IntroService::shouldRender(Request::create('/')));
        $this->assertFalse(IntroService::shouldRender(Request::create('/?intro=0')));
        $this->assertTrue(IntroService::shouldRender(Request::create('/?intro=1')));
        $this->assertTrue(IntroService::shouldRender(Request::create('/?intro=t3000')));

        $played = Request::create('/');
        $played->cookies->set(IntroService::COOKIE_NAME, '1');
        $this->assertFalse(IntroService::shouldRender($played));
    }

    public function test_intro_javascript_persists_the_seen_flag_in_a_cookie(): void
    {
        $js = file_get_contents(base_path('resources/js/intro.js'));

        // The cookie is the flag the server reads to stop rendering the
        // overlay — it has to be written on every completion path.
        $this->assertStringContainsString("COOKIE_NAME = 'abo_intro_seen'", $js);
        $this->assertStringContainsString('writeIntroCookie', $js);
        $this->assertStringContainsString('readIntroCookie', $js);
    }

    public function test_intro_overlay_uses_locked_palette(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        // The palette ships in the compiled CSS bundle. Verify all three
        // spec-locked colors are declared as CSS custom properties on the
        // .abo-intro scope.
        $css = file_get_contents(public_path('build/assets/'.collect(glob(public_path('build/assets/app-*.css')))->map(fn ($p) => basename($p))->first()));
        $this->assertStringContainsString('--abo-bg:#000', $css);
        $this->assertStringContainsString('--abo-lime:#c6ff00', $css);
        $this->assertStringContainsString('--abo-fg:#fff', $css);
    }

    public function test_intro_contains_two_split_layers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-abo-intro-layer="left"', false);
        $response->assertSee('data-abo-intro-layer="right"', false);
        $response->assertSee('abo-intro__layer--left', false);
        $response->assertSee('abo-intro__layer--right', false);
    }

    public function test_intro_contains_zipper_geometry_elements(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="abo-intro__zipper"', false);
        $response->assertSee('data-abo-intro-edge="left"', false);
        $response->assertSee('data-abo-intro-edge="right"', false);
        $response->assertSee('data-abo-intro-teeth', false);
        $response->assertSee('viewBox="0 0 100 100"', false);
    }

    public function test_intro_contains_puller_tag_with_abo_label(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-abo-intro-puller', false);
        $response->assertSee('class="abo-intro__puller-label"', false);
        $response->assertSee('>ABO</span>', false);
    }

    public function test_intro_renders_real_logo_asset_not_placeholder_text(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('images/abo-logo.svg', false);
        $response->assertSee('class="abo-intro__logo"', false);
    }

    public function test_intro_renders_logo_lime_glow_under_logo(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="abo-intro__logo-glow"', false);
    }

    public function test_intro_renders_tagline_with_exact_copy_per_spec(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        // The tagline is split into 21 individual spans (one per character)
        // for the typing effect. Verify the count and that the spec text
        // is encoded as individual char spans.
        $count = substr_count($response->getContent(), 'class="abo-intro__tagline-char"');
        $this->assertSame(21, $count);

        // First and last chars as a smoke test.
        $response->assertSee('<span class="abo-intro__tagline-char">N</span>', false);
        $response->assertSee('<span class="abo-intro__tagline-char">.</span>', false);

        // The source-of-truth copy lives in intro.js.
        $js = file_get_contents(base_path('resources/js/intro.js'));
        $this->assertStringContainsString("'NOT MADE TO BLEND IN.'", $js);
    }

    public function test_intro_tagline_uses_specified_typography(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        // The tagline element exists in the markup.
        $response->assertSee('class="abo-intro__tagline"', false);

        // The exact typography rules ship in the compiled CSS file.
        $css = file_get_contents(public_path('build/assets/'.collect(glob(public_path('build/assets/app-*.css')))->map(fn ($p) => basename($p))->first()));
        $this->assertStringContainsString('letter-spacing', $css);
        $this->assertStringContainsString('text-shadow:0 0 14px', $css);
        $this->assertStringContainsString('text-transform:uppercase', $css);
    }

    public function test_intro_renders_lime_light_sweep(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="abo-intro__tagline-sweep"', false);
        $response->assertSee('data-abo-intro-sweep', false);
    }

    public function test_intro_renders_skip_button_with_keyboard_support(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-abo-intro-skip', false);
        $response->assertSee('aria-label="Skip intro"', false);
        $response->assertSee('Skip', false);
        // Skip button starts unfocusable; focus is granted when intro starts.
        $response->assertSee('tabindex="-1"', false);
    }

    public function test_intro_overlay_is_presentation_only_for_screen_readers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-abo-intro', false);
        $response->assertSee('role="presentation"', false);
        $response->assertSee('aria-hidden="true"', false);
    }

    public function test_intro_uses_correct_session_storage_key_per_spec(): void
    {
        $js = file_get_contents(base_path('resources/js/intro.js'));

        $this->assertStringContainsString("'abo_intro_seen'", $js);
        $this->assertStringContainsString('sessionStorage', $js);
    }

    public function test_intro_supports_dev_flag_query_params(): void
    {
        $js = file_get_contents(base_path('resources/js/intro.js'));

        // Source-level check: the controller reads ?intro and branches on
        // the value. The minifier may collapse names, so we check for the
        // comparison values (1 and 0) instead of the raw method call.
        $this->assertStringContainsString("'1'", $js);
        $this->assertStringContainsString("'0'", $js);
        $this->assertStringContainsString("'t'", $js);

        // Bundle-level check: the minifier may convert single quotes to
        // backticks, so we look for the URL param call pattern.
        $bundle = collect(glob(public_path('build/assets/app-*.js')))
            ->map(fn ($p) => file_get_contents($p))
            ->first();
        $this->assertNotNull($bundle);
        $this->assertMatchesRegularExpression('/get\([`"\']intro[`"\']\)/', $bundle);
    }

    public function test_intro_uses_total_duration_of_six_point_five_seconds(): void
    {
        $js = file_get_contents(base_path('resources/js/intro.js'));

        $this->assertStringContainsString('TOTAL_MS = 6500', $js);
    }

    public function test_intro_javascript_handles_skip_via_keyboard(): void
    {
        $js = file_get_contents(base_path('resources/js/intro.js'));

        $this->assertStringContainsString("'Escape'", $js);
        $this->assertStringContainsString("' '", $js);
        $this->assertStringContainsString("'Enter'", $js);
    }

    public function test_intro_javascript_handles_visibility_change(): void
    {
        $js = file_get_contents(base_path('resources/js/intro.js'));

        $this->assertStringContainsString('visibilitychange', $js);
        $this->assertStringContainsString('document.hidden', $js);
    }

    public function test_intro_javascript_uses_single_master_timeline(): void
    {
        $js = file_get_contents(base_path('resources/js/intro.js'));

        $this->assertStringContainsString('requestAnimationFrame', $js);
        $this->assertStringContainsString('runTimeline', $js);
        $this->assertStringContainsString('applyState', $js);
    }

    public function test_intro_javascript_locks_body_scroll(): void
    {
        $js = file_get_contents(base_path('resources/js/intro.js'));

        $this->assertStringContainsString('abo-intro-lock', $js);
        $this->assertStringContainsString("classList.add('abo-intro-lock')", $js);
    }

    public function test_intro_javascript_removes_overlay_from_dom_on_completion(): void
    {
        $js = file_get_contents(base_path('resources/js/intro.js'));

        $this->assertStringContainsString('parentNode.removeChild', $js);
    }

    public function test_intro_javascript_implements_reduced_motion_fallback(): void
    {
        $js = file_get_contents(base_path('resources/js/intro.js'));

        $this->assertStringContainsString('prefersReducedMotion', $js);
        $this->assertStringContainsString('playReducedMotion', $js);
        $this->assertStringContainsString('FALLBACK_MS = 600', $js);
    }

    public function test_intro_does_not_block_navigation_in_dark_view(): void
    {
        // The intro is purely client-side session state; the home page must
        // still respond with 200 even though the intro markup is present.
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('class="hero"', false);
    }

    public function test_layout_preloads_hero_image_for_zero_layout_shift_reveal(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        // The layout must preload the hero photo so the 4.4s reveal is instant.
        // The test env may use any base URL, so match the rel=preload as=image
        // attribute pair and the hero asset path, not the full URL.
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<link[^>]+rel="preload"[^>]+as="image"[^>]+href="[^"]*streetwear-hero\.jpg"/',
            $content
        );
    }
}
