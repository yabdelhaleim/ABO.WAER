<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Builds the public URL of an image that is stored in the database.
 *
 * Product images (`product_images.path`), category images
 * (`categories.image`) and banner images (`banners.image_path`) are all
 * written to the `public` disk as *relative* paths — e.g.
 * `products/01JC…webp`. The browser reaches that disk through the
 * `storage` symlink, so the path has to be prefixed with `storage/`
 * before it is turned into a URL. Rendering `asset($path)` directly
 * points at `/products/01JC…webp`, which 404s the moment the admin
 * uploads a real photo.
 *
 * Every storefront view builds its image URLs through this class so a
 * stored path can never turn into a broken image.
 */
class ImageUrl
{
    /**
     * Shown when a product has no image of its own.
     */
    public const PRODUCT_PLACEHOLDER = 'store/images/product-placeholder.jpg';

    /**
     * Shown when a category has no image of its own.
     */
    public const CATEGORY_PLACEHOLDER = 'store/images/category-placeholder.jpg';

    /**
     * Where the `public` disk is mounted in the web root.
     */
    public const STORAGE_PREFIX = 'storage/';

    /**
     * Turn a stored path into a URL the browser can load.
     *
     * The rules, in order:
     *
     * 1. empty / null            → the placeholder
     * 2. already a URL           → returned untouched
     * 3. already under storage/  → asset() as-is, no doubled prefix
     * 4. anything else           → asset('storage/'.$path)
     *
     * @param  string  $placeholder  Fallback used when the path is empty.
     */
    public static function make(?string $path, string $placeholder = self::PRODUCT_PLACEHOLDER): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return asset($placeholder);
        }

        if (static::isUrl($path)) {
            return $path;
        }

        $normalized = ltrim($path, '/');

        if (Str::startsWith($normalized, self::STORAGE_PREFIX)) {
            return asset($normalized);
        }

        return asset(self::STORAGE_PREFIX.$normalized);
    }

    /**
     * The same URL, guaranteed absolute — what an Open Graph / Twitter
     * card needs. WhatsApp, Facebook and Instagram silently drop a
     * relative image.
     *
     * @param  string  $placeholder  Fallback used when the path is empty.
     */
    public static function absolute(?string $path, string $placeholder = self::PRODUCT_PLACEHOLDER): string
    {
        $url = static::make($path, $placeholder);

        // Protocol-relative ("//cdn.example.com/x.jpg") — complete it
        // with the scheme this request was served over.
        if (Str::startsWith($url, '//')) {
            $scheme = parse_url(url('/'), PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$url;
        }

        if (static::isUrl($url) && ! Str::startsWith($url, '//')) {
            return $url;
        }

        return url($url);
    }

    /**
     * Whether the path already points at something the browser can load
     * without any rewriting.
     */
    protected static function isUrl(string $path): bool
    {
        return Str::startsWith($path, ['http://', 'https://', '//']);
    }
}
