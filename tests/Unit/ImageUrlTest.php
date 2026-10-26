<?php

namespace Tests\Unit;

use App\Services\ImageUrl;
use Tests\TestCase;

/**
 * The five rules App\Services\ImageUrl applies to a stored image path.
 *
 * The helper is what keeps a photo uploaded through the admin from
 * rendering as a broken image: paths live on the `public` disk and are
 * reached through the `storage` symlink.
 */
class ImageUrlTest extends TestCase
{
    public function test_empty_path_falls_back_to_the_placeholder(): void
    {
        $this->assertSame(asset(ImageUrl::PRODUCT_PLACEHOLDER), ImageUrl::make(null));
        $this->assertSame(asset(ImageUrl::PRODUCT_PLACEHOLDER), ImageUrl::make(''));
        $this->assertSame(asset(ImageUrl::PRODUCT_PLACEHOLDER), ImageUrl::make('   '));
    }

    public function test_the_placeholder_can_be_chosen_per_call_site(): void
    {
        $this->assertSame(
            asset(ImageUrl::CATEGORY_PLACEHOLDER),
            ImageUrl::make(null, ImageUrl::CATEGORY_PLACEHOLDER),
        );
    }

    public function test_absolute_urls_are_returned_untouched(): void
    {
        $this->assertSame(
            'http://cdn.example.com/a.jpg',
            ImageUrl::make('http://cdn.example.com/a.jpg'),
        );
        $this->assertSame(
            'https://cdn.example.com/a.jpg',
            ImageUrl::make('https://cdn.example.com/a.jpg'),
        );
        $this->assertSame(
            '//cdn.example.com/a.jpg',
            ImageUrl::make('//cdn.example.com/a.jpg'),
        );
    }

    public function test_paths_already_under_storage_are_not_prefixed_twice(): void
    {
        $expected = asset('storage/products/a.jpg');

        $this->assertSame($expected, ImageUrl::make('/storage/products/a.jpg'));
        $this->assertSame($expected, ImageUrl::make('storage/products/a.jpg'));
    }

    public function test_relative_paths_are_prefixed_with_storage(): void
    {
        $expected = asset('storage/products/a.jpg');

        $this->assertSame($expected, ImageUrl::make('products/a.jpg'));
        $this->assertSame($expected, ImageUrl::make('/products/a.jpg'));
    }

    public function test_absolute_returns_a_full_url(): void
    {
        $url = ImageUrl::absolute('products/a.jpg');

        $this->assertStringStartsWith('http://', $url);
        $this->assertStringContainsString('/storage/products/a.jpg', $url);
    }

    public function test_absolute_leaves_an_absolute_url_alone(): void
    {
        $this->assertSame(
            'https://cdn.example.com/a.jpg',
            ImageUrl::absolute('https://cdn.example.com/a.jpg'),
        );
    }

    public function test_absolute_completes_a_protocol_relative_url(): void
    {
        $url = ImageUrl::absolute('//cdn.example.com/a.jpg');

        $this->assertStringStartsWith('http://', $url);
        $this->assertStringContainsString('//cdn.example.com/a.jpg', $url);
    }
}
