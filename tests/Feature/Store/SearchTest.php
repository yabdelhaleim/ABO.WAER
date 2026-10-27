<?php

namespace Tests\Feature\Store;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The search page is server-rendered: the term travels in the `q`
 * query string and the results come straight from the products table.
 * There is no client-side index and no static result list, so a URL
 * can be shared and a result set is whatever the database holds.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_page_loads_without_a_term(): void
    {
        $response = $this->get(route('search'));

        $response->assertOk();
        $response->assertViewIs('search');
        $response->assertViewHasAll(['term', 'products', 'categories']);
    }

    public function test_search_page_shows_the_term_in_the_form(): void
    {
        $response = $this->get(route('search', ['q' => 'hoodie']));

        $response->assertOk();
        $response->assertSee('name="q"', false);
        $response->assertSee('value="hoodie"', false);
    }

    public function test_search_finds_a_product_by_its_english_name(): void
    {
        Product::factory()->create(['name' => 'ABO Oversized Heavy Hoodie']);

        $response = $this->get(route('search', ['q' => 'hoodie']));

        $response->assertOk();
        $response->assertSee('ABO Oversized Heavy Hoodie');
    }

    public function test_search_finds_a_product_by_its_arabic_name(): void
    {
        Product::factory()->create([
            'name' => 'Heavy Hoodie',
            'name_ar' => 'هودي اوفرسايز ثقيل',
        ]);

        $response = $this->get(route('search', ['q' => 'هودي']));

        $response->assertOk();
        $response->assertSee('هودي اوفرسايز ثقيل');
    }

    public function test_search_finds_a_product_by_its_slug(): void
    {
        Product::factory()->create([
            'name' => 'Cargo Pant Olive',
            'slug' => 'cargo-pant-olive',
        ]);

        $response = $this->get(route('search', ['q' => 'cargo-pant']));

        $response->assertOk();
        $response->assertSee('Cargo Pant Olive');
    }

    public function test_an_english_term_shows_the_english_name_even_when_the_product_has_an_arabic_one(): void
    {
        Product::factory()->create([
            'name' => 'ABO Oversized Heavy Hoodie',
            'name_ar' => 'هودي اوفرسايز ثقيل',
        ]);

        $response = $this->get(route('search', ['q' => 'hoodie']));

        $response->assertOk();
        $response->assertSee('ABO Oversized Heavy Hoodie');
        $response->assertDontSee('هودي اوفرسايز ثقيل');
    }

    public function test_search_ignores_archived_products(): void
    {
        Product::factory()->create([
            'name' => 'Retired Hoodie',
            'is_archived' => true,
        ]);

        $response = $this->get(route('search', ['q' => 'retired']));

        $response->assertOk();
        $response->assertDontSee('Retired Hoodie');
        $response->assertSee('Nothing matches', false);
    }

    public function test_search_shows_the_empty_state_when_nothing_matches(): void
    {
        Product::factory()->create(['name' => 'ABO Oversized Heavy Hoodie']);

        $response = $this->get(route('search', ['q' => 'zzzz']));

        $response->assertOk();
        $response->assertDontSee('ABO Oversized Heavy Hoodie');
        $response->assertSee('Nothing matches');
    }

    public function test_search_lists_the_matching_categories(): void
    {
        Category::create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'is_active' => true,
            'is_visible' => true,
        ]);

        $response = $this->get(route('search', ['q' => 'hoodie']));

        $response->assertOk();
        $response->assertSee(route('shop', ['category' => 'hoodies']), false);
    }

    public function test_search_hides_categories_that_are_not_visible(): void
    {
        Category::create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'is_active' => true,
            'is_visible' => false,
        ]);

        $response = $this->get(route('search', ['q' => 'hoodie']));

        $response->assertOk();
        $response->assertDontSee(route('shop', ['category' => 'hoodies']), false);
    }

    public function test_search_escapes_a_term_that_contains_sql_wildcards(): void
    {
        Product::factory()->create(['name' => 'ABO Oversized Heavy Hoodie']);

        // A bare `%` would match every row if it reached the query as a
        // wildcard; the term is escaped, so it matches nothing.
        $response = $this->get(route('search', ['q' => '%']));

        $response->assertOk();
        $response->assertDontSee('ABO Oversized Heavy Hoodie');
    }

    public function test_the_header_search_control_links_to_the_search_page(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('href="'.route('search').'"', false);
        $response->assertSee('aria-label="Search"', false);
    }
}
