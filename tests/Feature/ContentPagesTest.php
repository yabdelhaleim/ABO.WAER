<?php

// ABO — Content pages smoke tests.
//
// Each test fetches one of the 5 content-page URLs and asserts the
// view returns 200 and the static shell. The page body is rendered
// server-side from the `pages` table (DB-driven via Store\PageController);
// RefreshDatabase runs PageSeeder in setUp so the 5 system slugs
// (shipping / returns / exchange / about / contact) exist before the
// request hits the controller.

namespace Tests\Feature;

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
        $response->assertSee('class="content__shell"', false);
        $response->assertSee('data-page-slug="shipping"', false);
        $response->assertSee('class="store-header"', false);
    }

    public function test_returns_page_renders(): void
    {
        $response = $this->get('/returns');

        $response->assertOk();
        $response->assertSee('class="content__shell"', false);
        $response->assertSee('data-page-slug="returns"', false);
    }

    public function test_exchange_page_renders(): void
    {
        $response = $this->get('/exchange');

        $response->assertOk();
        $response->assertSee('class="content__shell"', false);
        $response->assertSee('data-page-slug="exchange"', false);
    }

    public function test_about_page_renders(): void
    {
        $response = $this->get('/about');

        $response->assertOk();
        $response->assertSee('class="content__shell"', false);
        $response->assertSee('data-page-slug="about"', false);
    }

    public function test_contact_page_renders(): void
    {
        $response = $this->get('/contact');

        $response->assertOk();
        $response->assertSee('class="content__shell"', false);
        $response->assertSee('data-page-slug="contact"', false);
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
}
