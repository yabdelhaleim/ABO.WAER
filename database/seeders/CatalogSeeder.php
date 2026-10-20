<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Canonical catalog fixture — mirrors the editorial product set that
 * used to live in resources/views/store/data/home.php so the storefront
 * has something to render before the admin catalog is populated.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Hoodies',   'slug' => 'hoodies'],
            ['name' => 'T-Shirts',  'slug' => 't-shirts'],
            ['name' => 'Pants',     'slug' => 'pants'],
            ['name' => 'Jackets',   'slug' => 'jackets'],
        ];

        foreach ($categories as $data) {
            Category::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'is_active' => true,
                    'is_visible' => true,
                    'show_in_homepage' => true,
                    'sort_order' => 0,
                ],
            );
        }

        $colors = [
            ['name' => 'Onyx',         'hex' => '#0D0F11'],
            ['name' => 'Linen',        'hex' => '#F4F6F0'],
            ['name' => 'Lime',         'hex' => '#C6FF00'],
            ['name' => 'Olive',        'hex' => '#3E3D2B'],
            ['name' => 'Cement',       'hex' => '#8D928A'],
        ];

        foreach ($colors as $data) {
            Color::updateOrCreate(
                ['hex_code' => $data['hex']],
                ['name' => $data['name'], 'is_active' => true, 'sort_order' => 0],
            );
        }

        $sizes = [
            ['name' => 'S',  'sort_order' => 1],
            ['name' => 'M',  'sort_order' => 2],
            ['name' => 'L',  'sort_order' => 3],
            ['name' => 'XL', 'sort_order' => 4],
        ];

        foreach ($sizes as $data) {
            Size::updateOrCreate(
                ['name' => $data['name']],
                [
                    'group' => 'tops',
                    'is_active' => true,
                    'sort_order' => $data['sort_order'],
                ],
            );
        }

        $products = [
            [
                'name' => 'Drop 02 Hoodie',
                'slug' => 'drop-02-hoodie',
                'category' => 'hoodies',
                'price' => 1890,
                'badge' => 'NEW',
                'colors' => ['#0D0F11', '#F4F6F0', '#C6FF00'],
                'stock' => 10,
            ],
            [
                'name' => 'Urban Tee — Black',
                'slug' => 'urban-tee-black',
                'category' => 't-shirts',
                'price' => 690,
                'badge' => 'BEST SELLER',
                'colors' => ['#0D0F11', '#8D928A'],
                'stock' => 12,
            ],
            [
                'name' => 'Cargo Pant — Olive',
                'slug' => 'cargo-pant-olive',
                'category' => 'pants',
                'price' => 1450,
                'badge' => null,
                'colors' => ['#3E3D2B', '#0D0F11'],
                'stock' => 8,
            ],
            [
                'name' => 'Limo Bomber — Lime',
                'slug' => 'limo-bomber-lime',
                'category' => 'jackets',
                'price' => 2790,
                'badge' => 'LIMITED',
                'colors' => ['#C6FF00', '#0D0F11'],
                'stock' => 5,
            ],
        ];

        foreach ($products as $data) {
            $category = Category::where('slug', $data['category'])->first();
            if (! $category) {
                continue;
            }

            $product = Product::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'category_id' => $category->id,
                    'name' => $data['name'],
                    'description' => 'Premium '.$category->name.' piece from Drop 02.',
                    'is_archived' => false,
                    'is_featured' => true,
                    'badge' => $data['badge'],
                ],
            );

            foreach ($data['colors'] as $hex) {
                $color = Color::where('hex_code', $hex)->first();
                if (! $color) {
                    continue;
                }

                $pivot = ProductColor::firstOrCreate(
                    ['product_id' => $product->id, 'color_id' => $color->id],
                );

                if (! $pivot->images()->where('path', 'images/streetwear-hero.jpg')->exists()) {
                    ProductImage::create([
                        'product_color_id' => $pivot->id,
                        'path' => 'images/streetwear-hero.jpg',
                        'thumb_path' => null,
                        'sort_order' => 0,
                    ]);
                }

                if (! $product->colors()->where('colors.id', $color->id)->exists()) {
                    $product->colors()->attach($color->id);
                }

                foreach ($sizes as $sizeData) {
                    $size = Size::where('name', $sizeData['name'])->first();
                    if (! $size) {
                        continue;
                    }
                    Variant::firstOrCreate(
                        [
                            'product_id' => $product->id,
                            'color_id' => $color->id,
                            'size_id' => $size->id,
                        ],
                        [
                            'sku' => strtoupper(Str::random(10)),
                            'price' => $data['price'],
                            'cost' => max(100, $data['price'] - 500),
                            'discount_price' => null,
                            'quantity' => $data['stock'],
                        ],
                    );
                }
            }
        }
    }
}
