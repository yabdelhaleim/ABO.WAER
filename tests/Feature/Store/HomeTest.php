<?php

namespace Tests\Feature\Store;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_loads_with_required_data(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertViewIs('home');
        $response->assertViewHasAll(['banners', 'categories', 'featuredProducts']);
    }

    public function test_home_page_only_shows_active_banners(): void
    {
        $activeBanner = Banner::factory()->create([
            'title' => 'Active Banner Title',
            'is_active' => true,
        ]);

        $inactiveBanner = Banner::factory()->create([
            'title' => 'Inactive Banner Title',
            'is_active' => false,
        ]);

        $response = $this->get(route('home'));

        $response->assertSee('Active Banner Title');
        $response->assertDontSee('Inactive Banner Title');
    }

    public function test_home_page_shows_featured_categories(): void
    {
        $featuredCat = Category::create([
            'name' => 'Pants',
            'slug' => 'pants',
            'is_active' => true,
            'is_visible' => true,
            'show_in_homepage' => true,
        ]);

        $regularCat = Category::create([
            'name' => 'Belts',
            'slug' => 'belts',
            'is_active' => true,
            'is_visible' => true,
            'show_in_homepage' => false,
        ]);

        $response = $this->get(route('home'));

        $response->assertSee('Pants');
    }

    public function test_home_page_shows_active_featured_products(): void
    {
        $featuredProduct = Product::factory()->create([
            'name' => 'Super Slim Shirt',
            'is_featured' => true,
            'is_archived' => false,
        ]);

        $archivedProduct = Product::factory()->create([
            'name' => 'Old Archived Hoodie',
            'is_featured' => true,
            'is_archived' => true,
        ]);

        $response = $this->get(route('home'));

        $response->assertSee('Super Slim Shirt');
        $response->assertDontSee('Old Archived Hoodie');
    }
}
