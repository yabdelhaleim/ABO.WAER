<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontLiveAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Hoodies',
            'name_ar' => 'هوديز',
            'slug' => 'hoodies',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $color = Color::create([
            'name' => 'Black',
            'name_ar' => 'أسود',
            'hex_code' => '#0D0F11',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $size = Size::create([
            'name' => 'XL',
            'group' => 'letter',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Heavy Streetwear Hoodie',
            'name_ar' => 'هودي ستريت وير قطن ثقيل',
            'slug' => 'heavy-streetwear-hoodie',
            'description' => 'Premium oversized heavy hoodie.',
            'is_featured' => true,
            'is_archived' => false,
        ]);

        Variant::create([
            'product_id' => $this->product->id,
            'color_id' => $color->id,
            'size_id' => $size->id,
            'sku' => 'ABO-HD-BLK-XL',
            'price' => 1200,
            'cost' => 600,
            'quantity' => 20,
        ]);

        Banner::create([
            'title' => 'Drop 02: Winter Streetwear',
            'image_path' => 'banners/hero-winter.jpg',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_home_page_renders_with_intro_and_hero(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);

        // Intro component must be embedded
        $response->assertSee('data-abo-intro', false);
        $response->assertSee('abo-intro__veil', false);

        // Hero section and banner image path
        $response->assertSee('hero__photo', false);
        $response->assertSee('storage/banners/hero-winter.jpg', false);
    }

    public function test_product_detail_page_renders_sizes_and_size_guide_modal(): void
    {
        $response = $this->get('/shop/'.$this->product->slug);
        $response->assertStatus(200);

        // Product sizes list and button
        $response->assertSee('product-page__sizes', false);
        $response->assertSee('product-page__size-btn', false);
        $response->assertSee('XL', false);

        // Size guide modal trigger and dialog
        $response->assertSee('data-size-guide-modal', false);
        $response->assertSee('SIZE GUIDE', false);
    }

    public function test_storefront_routes_all_return_200(): void
    {
        $routes = [
            '/',
            '/shop',
            '/shop/'.$this->product->slug,
            '/cart',
            '/checkout',
            '/login',
            '/register',
            '/sitemap.xml',
        ];

        foreach ($routes as $uri) {
            $response = $this->get($uri);
            $response->assertStatus(200);
        }
    }
}
