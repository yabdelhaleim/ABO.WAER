<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Color;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * Coverage for the crawler-facing surface added in Phase 9: the generated
 * /sitemap.xml, public/robots.txt, the per-product share card, and the
 * custom error pages that replace Laravel's default white screens.
 *
 * Uses factories for every fixture so each test owns its own catalog and
 * nothing leaks between tests through shared rows.
 */
class SeoAndErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected Category $hoodies;

    protected Color $onyx;

    protected Size $sizeL;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoodies = Category::factory()->create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'is_active' => true,
            'is_visible' => true,
        ]);

        $this->onyx = Color::factory()->create([
            'name' => 'Onyx',
            'hex_code' => '#0D0F11',
            'is_active' => true,
        ]);

        $this->sizeL = Size::factory()->create([
            'name' => 'L',
            'sort_order' => 3,
            'is_active' => true,
        ]);
    }

    /**
     * Build a product with one color, one size variant and a primary image
     * so the product page has a real og:image and a real price to render.
     */
    protected function attachCatalog(Product $product, float $price = 1490.0, int $stock = 8): void
    {
        $pivot = ProductColor::create([
            'product_id' => $product->id,
            'color_id' => $this->onyx->id,
        ]);

        $product->colors()->syncWithoutDetaching([$this->onyx->id]);

        ProductImage::create([
            'product_color_id' => $pivot->id,
            'path' => 'images/streetwear-hero.jpg',
            'sort_order' => 0,
        ]);

        Variant::create([
            'product_id' => $product->id,
            'color_id' => $this->onyx->id,
            'size_id' => $this->sizeL->id,
            'sku' => strtoupper(Str::random(10)),
            'price' => $price,
            'cost' => $price / 2,
            'discount_price' => null,
            'quantity' => $stock,
        ]);
    }

    /**
     * Parse the sitemap response and return every <loc> as a string.
     *
     * simplexml_load_string() is the assertion that matters most: it fails
     * on a document with a mis-placed XML declaration or an unescaped `&`
     * in a query string, which is exactly the class of bug a hand-rolled
     * sitemap template produces.
     *
     * @return list<string>
     */
    protected function sitemapLocations(): array
    {
        $response = $this->get(route('sitemap'));
        $response->assertOk();

        $xml = simplexml_load_string($response->getContent());
        $this->assertInstanceOf(
            SimpleXMLElement::class,
            $xml,
            'The sitemap must be parseable XML — check the declaration and any unescaped & in a URL.',
        );

        $locations = [];
        foreach ($xml->url as $url) {
            $locations[] = (string) $url->loc;
        }

        return $locations;
    }

    public function test_sitemap_is_served_as_xml(): void
    {
        $response = $this->get(route('sitemap'));

        $response->assertOk();

        // Header is asserted through get() rather than assertHeader() because
        // the framework may append "; charset=UTF-8" to the content type.
        $contentType = $response->headers->get('Content-Type');
        $this->assertNotNull($contentType, 'The sitemap must declare a Content-Type.');
        $this->assertStringContainsString('application/xml', $contentType);

        $this->assertStringContainsString('<urlset', $response->getContent());
        $this->assertStringContainsString(
            'http://www.sitemaps.org/schemas/sitemap/0.9',
            $response->getContent(),
        );
    }

    public function test_sitemap_lists_the_homepage_and_shop(): void
    {
        $locations = $this->sitemapLocations();

        $this->assertContains(route('home'), $locations);
        $this->assertContains(route('shop'), $locations);
    }

    public function test_sitemap_lists_active_products_and_excludes_archived_ones(): void
    {
        $live = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'category_id' => $this->hoodies->id,
            'is_archived' => false,
        ]);
        $this->attachCatalog($live);

        $archived = Product::factory()->archived()->create([
            'name' => 'Discontinued Hoodie',
            'slug' => 'discontinued-hoodie',
            'category_id' => $this->hoodies->id,
        ]);

        $locations = $this->sitemapLocations();

        $this->assertContains(route('shop.show', ['slug' => $live->slug]), $locations);
        $this->assertNotContains(
            route('shop.show', ['slug' => $archived->slug]),
            $locations,
            'An archived product must never reach the sitemap — it would be indexed as a soft 404.',
        );
    }

    public function test_sitemap_carries_a_lastmod_for_products(): void
    {
        $product = Product::factory()->create([
            'slug' => 'drop-02-hoodie',
            'category_id' => $this->hoodies->id,
            'is_archived' => false,
        ]);
        $this->attachCatalog($product);

        $response = $this->get(route('sitemap'));

        $response->assertOk();
        $response->assertSee('<lastmod>', false);
        // W3C datetime, e.g. 2026-10-20T09:30:00+00:00
        $this->assertMatchesRegularExpression(
            '/<lastmod>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-Z]/',
            $response->getContent(),
        );
    }

    public function test_sitemap_lists_active_categories_and_excludes_inactive_ones(): void
    {
        $hidden = Category::factory()->create([
            'name' => 'Secret Archive',
            'slug' => 'secret-archive',
            'is_active' => false,
        ]);

        $locations = $this->sitemapLocations();

        $this->assertContains(route('shop', ['category' => $this->hoodies->slug]), $locations);
        $this->assertNotContains(
            route('shop', ['category' => $hidden->slug]),
            $locations,
            'An inactive category must not be advertised to crawlers.',
        );
    }

    public function test_sitemap_lists_active_content_pages_only(): void
    {
        Page::factory()->create(['slug' => 'about', 'title' => 'About us', 'is_active' => true]);
        Page::factory()->create(['slug' => 'contact', 'title' => 'Contact', 'is_active' => true]);
        Page::factory()->create(['slug' => 'shipping', 'title' => 'Shipping', 'is_active' => true]);
        Page::factory()->create(['slug' => 'returns', 'title' => 'Returns', 'is_active' => true]);
        Page::factory()->create(['slug' => 'exchange', 'title' => 'Exchange', 'is_active' => true]);

        // Published=false content must stay out, and so must a slug with no
        // dedicated route — route() would throw on the latter.
        Page::factory()->create(['slug' => 'faq', 'title' => 'FAQ', 'is_active' => true]);
        Page::factory()->create(['slug' => 'about-draft', 'title' => 'Draft', 'is_active' => false]);

        $locations = $this->sitemapLocations();

        $this->assertContains(route('about'), $locations);
        $this->assertContains(route('contact'), $locations);
        $this->assertContains(route('shipping'), $locations);
        $this->assertContains(route('returns'), $locations);
        $this->assertContains(route('exchange'), $locations);

        // An unpublished page must stay out...
        $this->assertNotContains(url('/about-draft'), $locations);
        // ...and so must a slug with no dedicated route, which route()
        // would throw on if it ever slipped through.
        $this->assertNotContains(url('/faq'), $locations);
    }

    public function test_sitemap_escapes_ampersands_in_category_urls(): void
    {
        $response = $this->get(route('sitemap'));
        $response->assertOk();

        // Blade escapes & as &amp; — without it the document is not valid XML.
        $this->assertStringContainsString('category=hoodies', $response->getContent());
        $this->assertStringNotContainsString('?category=hoodies&', $response->getContent());
    }

    public function test_robots_txt_allows_the_catalog_and_blocks_private_routes(): void
    {
        // robots.txt is a static file served by the web server, not by
        // Laravel, so it is read from disk rather than requested over HTTP.
        $robotsPath = public_path('robots.txt');
        $this->assertFileExists($robotsPath);

        $robots = file_get_contents($robotsPath);

        $this->assertStringContainsString('User-agent: *', $robots);
        $this->assertStringContainsString('Allow: /', $robots);

        foreach (['/admin/', '/checkout/', '/cart/', '/account/'] as $disallowed) {
            $this->assertStringContainsString('Disallow: '.$disallowed, $robots);
        }

        $this->assertStringContainsString('Sitemap: https://abostore.com/sitemap.xml', $robots);
    }

    public function test_product_page_renders_a_dynamic_share_card(): void
    {
        $product = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'description' => 'Heavyweight fleece, urban cut.',
            'category_id' => $this->hoodies->id,
            'is_archived' => false,
        ]);
        $this->attachCatalog($product, price: 1490.0);

        $response = $this->get(route('shop.show', ['slug' => 'drop-02-hoodie']));

        $response->assertOk();

        // The product's own photo, not the generic hero shot.
        $response->assertSee('og:type" content="product', false);
        $response->assertSee('og:title" content="Drop 02 Hoodie', false);
        $response->assertSee(
            'og:image" content="'.asset('images/streetwear-hero.jpg').'"',
            false,
        );

        // Price + currency in the wire format Facebook's parser expects.
        $response->assertSee('product:price:currency" content="EGP', false);
        $response->assertSee('product:price:amount" content="1490.00', false);
        $response->assertSee('product:availability" content="in stock', false);

        // Canonical + Twitter card follow the same image.
        $response->assertSee('rel="canonical"', false);
        $response->assertSee('twitter:card" content="summary_large_image', false);
        $response->assertSee('name="twitter:image" content="'.asset('images/streetwear-hero.jpg').'"', false);
    }

    public function test_share_card_never_carries_the_title_suffix(): void
    {
        $product = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'category_id' => $this->hoodies->id,
            'is_archived' => false,
        ]);
        $this->attachCatalog($product);

        $content = $this->get(route('shop.show', ['slug' => 'drop-02-hoodie']))->getContent();

        // The <title> keeps the suffix, the OG card must not.
        $this->assertStringContainsString("— Premium Men's Streetwear</title>", $content);
        $this->assertStringNotContainsString(
            'og:title" content="Drop 02 Hoodie — ABO — Premium Men',
            $content,
        );
    }

    /**
     * Register a throwaway route that aborts with $status, hit it, and assert
     * the branded error page came back.
     *
     * Nothing in the app raises 403 or 500 on demand — the admin guard
     * redirects to the login screen instead of forbidding — so aborting
     * explicitly is the only way to exercise the real
     * handler -> errors/{code}.blade.php pipeline.
     */
    protected function assertErrorPageRenders(int $status, string $expectedText): void
    {
        $uri = '/__test-abort-'.$status;

        Route::middleware('web')->get($uri, fn () => abort($status));

        $response = $this->get($uri);

        $response->assertStatus($status);
        // escape: false — the copy below is literal HTML in the template
        // (including a real apostrophe in "You don't"), so the expectation
        // must not be run through e() the way assertSee does by default.
        $response->assertSee($expectedText, false);
        // The ABO layout, not Laravel's default error screen.
        $response->assertSee('abo-store-body', false);
        $response->assertDontSee('Whoops, looks like something went wrong', false);
    }

    public function test_non_existent_page_renders_the_custom_404(): void
    {
        $response = $this->get('/non-existing-product-xyz');

        $response->assertNotFound();
        $response->assertSee('This page took a different route');
        $response->assertSee('404');
        $response->assertSee('Back to Shop');

        // It must be the ABO-styled page, not Laravel's default error screen.
        $response->assertSee('abo-store-body', false);
        $response->assertDontSee('Whoops, looks like something went wrong', false);
    }

    public function test_inactive_product_slug_renders_the_custom_404(): void
    {
        Product::factory()->archived()->create([
            'slug' => 'gone-hoodie',
            'category_id' => $this->hoodies->id,
        ]);

        $response = $this->get(route('shop.show', ['slug' => 'gone-hoodie']));

        $response->assertNotFound();
        $response->assertSee('This page took a different route');
    }

    public function test_forbidden_response_renders_the_custom_403(): void
    {
        $this->assertErrorPageRenders(403, "You don't have permission to access this area");
    }

    public function test_server_error_response_renders_the_custom_500(): void
    {
        $this->assertErrorPageRenders(500, 'Something went wrong on our side');
    }

    public function test_custom_error_views_are_registered(): void
    {
        // Laravel resolves HTTP errors through the `errors::` view namespace,
        // which maps to resources/views/errors/{code}.blade.php. The paths
        // are only registered lazily inside the exception handler, so this
        // asserts the files are on disk rather than asking the View facade.
        foreach ([404, 403, 500] as $code) {
            $this->assertFileExists(
                resource_path("views/errors/{$code}.blade.php"),
                "A custom {$code} view must exist.",
            );
        }
    }

    public function test_error_pages_never_leak_exception_details(): void
    {
        // The shopper-facing 500 page must stay vague about the cause; the
        // detail belongs in storage/logs.
        $uri = '/__test-abort-500';
        Route::middleware('web')->get($uri, fn () => abort(500, 'Database connection refused at 10.0.0.4'));

        $content = $this->get($uri)->getContent();

        $this->assertStringContainsString('our technical team is already on it', $content);
        $this->assertStringNotContainsString('Database connection refused', $content);
        $this->assertStringNotContainsString('10.0.0.4', $content);
        $this->assertStringNotContainsStringIgnoringCase('stack trace', $content);
    }
}
