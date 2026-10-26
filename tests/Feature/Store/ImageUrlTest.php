<?php

namespace Tests\Feature\Store;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The storefront must never render an image the browser cannot load.
 *
 * Photos live on the `public` disk under a relative path, so every URL
 * the views build has to carry the `storage/` prefix. These tests pin
 * that behaviour on the three pages that show a database image: the
 * shop grid, the product page and the home page.
 */
class ImageUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_grid_points_product_images_at_the_storage_disk(): void
    {
        $product = $this->productWithImage('products/hoodie.webp');

        $response = $this->get(route('shop'));

        $response->assertOk();
        $response->assertSee('/storage/products/hoodie.webp', false);
    }

    public function test_product_page_points_product_images_at_the_storage_disk(): void
    {
        $product = $this->productWithImage('products/hoodie.webp');

        $response = $this->get(route('shop.show', ['slug' => $product->slug]));

        $response->assertOk();
        $response->assertSee('/storage/products/hoodie.webp', false);
    }

    public function test_product_page_share_card_uses_a_full_image_url(): void
    {
        $product = $this->productWithImage('products/hoodie.webp');

        $response = $this->get(route('shop.show', ['slug' => $product->slug]));

        $response->assertOk();

        $content = $response->getContent();

        $this->assertSame(
            1,
            preg_match('/<meta property="og:image" content="([^"]+)"/', $content, $matches),
            'The product page must publish an og:image tag.',
        );

        $ogImage = $matches[1];

        // Open Graph images are dropped by WhatsApp / Facebook / Instagram
        // unless they carry a scheme and a host.
        $this->assertStringStartsWith('http://', $ogImage);
        $this->assertStringContainsString('/storage/products/hoodie.webp', $ogImage);
    }

    public function test_home_page_points_category_images_at_the_storage_disk(): void
    {
        $category = Category::factory()->create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'image' => 'categories/hoodies.webp',
            'is_active' => true,
            'is_visible' => true,
            'show_in_homepage' => true,
        ]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('/storage/categories/hoodies.webp', false);
    }

    public function test_home_page_falls_back_to_the_category_placeholder(): void
    {
        Category::factory()->create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'image' => null,
            'is_active' => true,
            'is_visible' => true,
            'show_in_homepage' => true,
        ]);

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('/store/images/category-placeholder.jpg', false);
    }

    public function test_a_product_without_an_image_falls_back_to_the_placeholder(): void
    {
        $product = Product::factory()->create([
            'name' => 'Bare Hoodie',
            'slug' => 'bare-hoodie',
            'is_archived' => false,
        ]);

        $response = $this->get(route('shop.show', ['slug' => $product->slug]));

        $response->assertOk();
        $response->assertSee('/store/images/product-placeholder.jpg', false);
    }

    /**
     * A product with a single colour, one image and one in-stock variant.
     */
    protected function productWithImage(string $path): Product
    {
        $color = Color::factory()->create(['is_active' => true]);

        $product = Product::factory()->create([
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'is_archived' => false,
        ]);

        $pivot = ProductColor::create([
            'product_id' => $product->id,
            'color_id' => $color->id,
        ]);

        ProductImage::create([
            'product_color_id' => $pivot->id,
            'path' => $path,
        ]);

        return $product;
    }
}
