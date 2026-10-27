<?php

// ABO — Content pages feature tests.
//
// The five content pages are rendered from the `pages` table by
// App\Http\Controllers\Store\PageController → resources/views/content.blade.php.
// There is no client-side renderer any more: the former resources/js/content.js
// used to wipe the server-rendered HTML and replace it with static mock data,
// which meant the admin's saved copy never reached the customer.
//
// These tests cover that contract:
//   - the page shows the copy that is in the database,
//   - the English copy is preferred and the Arabic copy is the fallback,
//   - the response carries no mock content,
//   - the shipped JavaScript bundle can no longer replace the markup.

namespace Tests\Feature;

use App\Models\Page;
use Database\Seeders\PageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the system pages so every route resolves to a real
        // Page record. Without this, RefreshDatabase leaves the
        // table empty and the controller returns 404.
        $this->seed(PageSeeder::class);
    }

    public function test_shipping_page_renders(): void
    {
        $response = $this->get('/shipping');

        $response->assertOk();
        $response->assertSee('data-page-slug="shipping"', false);
        $response->assertSee('<h1 class="content__title">Shipping Policy</h1>', false);
        $response->assertSee('class="store-header"', false);
    }

    public function test_returns_page_renders(): void
    {
        $response = $this->get('/returns');

        $response->assertOk();
        $response->assertSee('data-page-slug="returns"', false);
        $response->assertSee('<h1 class="content__title">Returns Policy</h1>', false);
    }

    public function test_exchange_page_renders(): void
    {
        $response = $this->get('/exchange');

        $response->assertOk();
        $response->assertSee('data-page-slug="exchange"', false);
        $response->assertSee('<h1 class="content__title">Exchange Policy</h1>', false);
    }

    public function test_about_page_renders(): void
    {
        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('data-page-slug="about"', false);
        $response->assertSee('<h1 class="content__title">About Us</h1>', false);
    }

    public function test_contact_page_renders(): void
    {
        $response = $this->get('/contact');

        $response->assertOk();
        $response->assertSee('data-page-slug="contact"', false);
        $response->assertSee('<h1 class="content__title">Contact Us</h1>', false);
    }

    public function test_content_pages_have_named_routes(): void
    {
        foreach (['shipping', 'returns', 'exchange', 'about', 'contact'] as $slug) {
            $this->assertNotNull(route($slug));
            $this->assertStringEndsWith('/'.$slug, route($slug));
        }
    }

    public function test_header_nav_links_to_about_and_contact_routes(): void
    {
        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('href="'.route('about').'"', false);
        $response->assertSee('href="'.route('contact').'"', false);
    }

    // ─── The database is the source of truth ───────────────────

    public function test_about_page_shows_the_copy_stored_in_the_database(): void
    {
        Page::where('slug', 'about')->update([
            'content' => '<h2>Real copy</h2><p>STORED-IN-THE-DATABASE</p>',
            'content_ar' => null,
        ]);

        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('STORED-IN-THE-DATABASE', false);
        $response->assertSee('<h2>Real copy</h2>', false);
    }

    public function test_page_shows_the_english_copy_when_the_arabic_copy_is_missing(): void
    {
        Page::where('slug', 'about')->update([
            'title' => 'About ABO Fashion',
            'content' => '<p>ENGLISH-COPY</p>',
            'content_ar' => null,
        ]);

        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('ENGLISH-COPY', false);
        $response->assertSee('<h1 class="content__title">About ABO Fashion</h1>', false);
        $response->assertSee('lang="en"', false);
        $response->assertSee('dir="ltr"', false);
    }

    public function test_page_falls_back_to_the_arabic_copy_when_the_english_copy_is_missing(): void
    {
        Page::where('slug', 'about')->update([
            'title' => 'About ABO Fashion',
            'title_ar' => 'عن أبو فاشون',
            'content' => null,
            'content_ar' => '<p>النص-العربي</p>',
        ]);

        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('النص-العربي', false);
        // The page is served in the language of its body, so the
        // heading follows the copy.
        $response->assertSee('عن أبو فاشون', false);
        $response->assertSee('lang="ar"', false);
        $response->assertSee('dir="rtl"', false);
    }

    public function test_page_shows_the_empty_state_when_the_record_has_no_copy(): void
    {
        Page::where('slug', 'about')->update(['content' => null, 'content_ar' => null]);

        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('This page has no content yet.');
    }

    public function test_policy_pages_show_the_policy_tabs(): void
    {
        $response = $this->get('/shipping');

        $response->assertOk();
        $response->assertSee('href="'.route('shipping').'"', false);
        $response->assertSee('href="'.route('returns').'"', false);
        $response->assertSee('href="'.route('exchange').'"', false);
    }

    // ─── Every page carries copy in both languages ──────────────
    //
    // The five pages were shipped with an empty body: the seeder held
    // the copy but the columns in the database were null, so the
    // storefront rendered "This page has no content yet." for the
    // contact, shipping, returns and exchange pages.

    public function test_every_seeded_page_has_copy_in_both_languages(): void
    {
        $pages = Page::query()->whereIn('slug', ['about', 'contact', 'shipping', 'returns', 'exchange'])->get();

        $this->assertCount(5, $pages);

        foreach ($pages as $page) {
            $this->assertNotEmpty(trim((string) $page->content), "The [{$page->slug}] page has no English copy.");
            $this->assertNotEmpty(trim((string) $page->content_ar), "The [{$page->slug}] page has no Arabic copy.");
        }
    }

    public function test_every_storefront_page_renders_a_body(): void
    {
        foreach (['about', 'contact', 'shipping', 'returns', 'exchange'] as $slug) {
            $response = $this->get('/'.$slug);

            $response->assertOk();
            $response->assertDontSee('This page has no content yet.');
        }
    }

    public function test_the_seeder_fills_a_page_whose_body_was_emptied(): void
    {
        Page::where('slug', 'contact')->update(['content' => null, 'content_ar' => '']);

        $this->seed(PageSeeder::class);

        $page = Page::where('slug', 'contact')->firstOrFail();

        $this->assertNotEmpty(trim((string) $page->content));
        $this->assertNotEmpty(trim((string) $page->content_ar));
    }

    public function test_the_seeder_fills_only_the_language_that_is_missing(): void
    {
        Page::where('slug', 'contact')->update([
            'content' => '<p>ADMIN-ENGLISH-COPY</p>',
            'content_ar' => null,
        ]);

        $this->seed(PageSeeder::class);

        $page = Page::where('slug', 'contact')->firstOrFail();

        $this->assertSame('<p>ADMIN-ENGLISH-COPY</p>', $page->content);
        $this->assertNotEmpty(trim((string) $page->content_ar));
    }

    public function test_the_seeder_never_overwrites_a_saved_copy(): void
    {
        Page::where('slug', 'contact')->update([
            'content' => '<p>ADMIN-ENGLISH-COPY</p>',
            'content_ar' => '<p>نسخة-المدير</p>',
        ]);

        $this->seed(PageSeeder::class);

        $page = Page::where('slug', 'contact')->firstOrFail();

        $this->assertSame('<p>ADMIN-ENGLISH-COPY</p>', $page->content);
        $this->assertSame('<p>نسخة-المدير</p>', $page->content_ar);
    }

    // ─── No mock content can reach the customer ────────────────

    public function test_response_contains_no_mock_content(): void
    {
        $response = $this->get('/about');

        $response->assertOk();
        // Strings that only ever lived in resources/js/content.mock.js.
        $response->assertDontSee('Fast delivery across Egypt', false);
        $response->assertDontSee('STILL NEED HELP?', false);
        $response->assertDontSee('LAST UPDATED: 2026-10-12', false);
    }

    public function test_the_content_renderer_and_its_mock_data_are_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('js/content.js'));
        $this->assertFileDoesNotExist(resource_path('js/content.mock.js'));
    }

    public function test_the_storefront_entry_point_does_not_import_the_content_renderer(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString("import './content.js'", $source);
    }

    public function test_the_shipped_bundle_can_no_longer_replace_the_page_markup(): void
    {
        $bundle = $this->storefrontBundle();

        $this->assertStringNotContainsString('content__shell', $bundle);
        $this->assertStringNotContainsString('Not made to blend in.', $bundle);
        $this->assertStringNotContainsString('Fast delivery across Egypt', $bundle);
    }

    /**
     * The compiled storefront bundle Vite ships for resources/js/app.js.
     *
     * The assets are committed to the repository, so the bundle that
     * actually reaches the browser is part of the contract — not the
     * source that produced it.
     */
    private function storefrontBundle(): string
    {
        $manifestPath = public_path('build/manifest.json');
        $this->assertFileExists($manifestPath);

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('resources/js/app.js', $manifest);

        $bundlePath = public_path('build/'.$manifest['resources/js/app.js']['file']);
        $this->assertFileExists($bundlePath);

        return file_get_contents($bundlePath);
    }
}
