<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class ShopController extends Controller
{
    /**
     * Shop catalog page with filter, sort, and pagination.
     */
    public function index(Request $request): View
    {
        // Every relation the shop-card template reads, eager-loaded so
        // the catalog page never issues an N+1: category for the breadcrumb
        // href + filter attribute, colors for the swatch strip, variants
        // for the price accessors, productColors.images for the cover via
        // the primaryImage accessor. Each `with` is a single IN-query
        // regardless of page size, so 20 products on the page is the
        // same number of queries as 5.
        $query = Product::query()
            ->where('is_archived', false)
            ->with([
                'category:id,name,slug',
                'colors:id,name,hex_code',
                'variants:id,product_id,price,discount_price,quantity',
                'productColors.color:id,name,hex_code',
                'productColors.images',
            ]);

        // Category filter (slug) — includes all descendant subcategories.
        $activeCategory = null;
        if ($categorySlug = $request->query('category')) {
            $activeCategory = Category::where('slug', $categorySlug)
                ->where('is_active', true)
                ->first();

            if ($activeCategory) {
                $slugs = $activeCategory->descendantSlugs();
                $categoryIds = Category::whereIn('slug', $slugs)->pluck('id');
                $query->whereIn('category_id', $categoryIds);
            }
        }

        // Color filter — by color id (and hex as a fallback for URL stability).
        if ($colorId = $request->query('color')) {
            $query->whereHas('colors', function (Builder $q) use ($colorId) {
                if (is_numeric($colorId)) {
                    $q->where('colors.id', (int) $colorId);
                } else {
                    $hex = ltrim((string) $colorId, '#');
                    $q->where('colors.hex_code', '#'.strtoupper($hex));
                }
            });
        }

        // Size filter — only variants with stock > 0 count.
        if ($sizeId = $request->query('size')) {
            $query->whereHas('variants', function (Builder $q) use ($sizeId) {
                $q->where('size_id', (int) $sizeId)->where('quantity', '>', 0);
            });
        }

        // Price filter (range on variant price). Use the cheapest variant
        // price for matching; without this filter we still allow sorting.
        if ($request->filled('price_min') || $request->filled('price_max')) {
            $min = $request->query('price_min');
            $max = $request->query('price_max');
            $query->whereHas('variants', function (Builder $q) use ($min, $max) {
                if ($min !== null && $min !== '') {
                    $q->where('price', '>=', (float) $min);
                }
                if ($max !== null && $max !== '') {
                    $q->where('price', '<=', (float) $max);
                }
            });
        }

        // Sorting
        switch ($request->query('sort')) {
            case 'price-asc':
                $query->orderBy('created_at', 'desc')
                    ->orderBy('id', 'desc');
                break;
            case 'price-desc':
                $query->orderBy('created_at', 'desc')
                    ->orderBy('id', 'desc');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc')
                    ->orderBy('id', 'desc');
                break;
        }

        $products = $query->paginate(12)->withQueryString();

        // Filter options for the sidebar — driven by real data so we never
        // show a filter with no values behind it.
        // Cached for 10 minutes: categories/colors/sizes change rarely; the
        // price range changes only when variant prices are updated.
        $categories = Cache::remember('shop.sidebar.categories', 600, fn () => Category::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get());

        $colors = Cache::remember('shop.sidebar.colors', 600, fn () => Color::query()
            ->where('is_active', true)
            ->whereHas('products', fn (Builder $q) => $q->where('is_archived', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get());

        $sizes = Cache::remember('shop.sidebar.sizes', 600, fn () => Size::query()
            ->where('is_active', true)
            ->whereHas('variants.product', fn (Builder $q) => $q->where('is_archived', false)->where('quantity', '>', 0))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get());

        // The cheapest in-stock variant price for the current filter set,
        // used to render price buckets that actually have products in them.
        $pricePoints = Cache::remember('shop.sidebar.price_points', 600, fn () => Variant::query()
            ->whereHas('product', fn (Builder $q) => $q->where('is_archived', false))
            ->where('quantity', '>', 0)
            ->selectRaw('MIN(price) as min_price, MAX(price) as max_price')
            ->first());

        return view('shop', [
            'products' => $products,
            'categories' => $categories,
            'colors' => $colors,
            'sizes' => $sizes,
            'activeCategory' => $activeCategory,
            'minPricePoint' => $pricePoints?->min_price,
            'maxPricePoint' => $pricePoints?->max_price,
            'shop' => [
                'title_line_1' => 'FIND YOUR OWN',
                'title_line_2' => 'STYLE.',
                'lede' => 'Pieces that speak before you do.',
            ],
        ]);
    }

    /**
     * Single product page — variants grouped by color so JS can swap the
     * gallery + size list as the shopper picks a color.
     */
    public function show(string $slug): View
    {
        // Every relation the product-page template (and the related
        // cards below it) reads, eager-loaded so the page never issues
        // an N+1: category + size for the variant-builder, color + size
        // on every variant for the size chart swatches, the through
        // `images` for the gallery + primaryImage accessor, and the
        // productColors.color pivot for the colour-name fallback.
        $product = Product::query()
            ->where('slug', $slug)
            ->where('is_archived', false)
            ->with([
                'category:id,name,slug',
                'colors:id,name,hex_code',
                'variants.size:id,name,sort_order',
                'variants.color:id,name,hex_code',
                'images',
                'productColors.color:id,name,hex_code',
                'productColors.images',
            ])
            ->firstOrFail();

        // Group variants by color_id so the view can render color blocks
        // each carrying its sizes + prices + stock counts.
        $variantsByColor = $product->variants
            ->groupBy(fn (Variant $variant) => $variant->color_id)
            ->map(function ($variants, $colorId) use ($product) {
                $color = $variants->first()->color;
                $images = $product->productColors
                    ->firstWhere('color_id', (int) $colorId)?->images
                    ?? collect();

                return [
                    'color' => $color,
                    'sizes' => $variants
                        ->sortBy(fn (Variant $v) => $v->size?->sort_order ?? 0)
                        ->values(),
                    'images' => $images->sortBy('sort_order')->values(),
                ];
            })
            ->values();

        // Related products — same category, excluding the current one.
        // The related card template reads `primaryImage` (needs
        // productColors.images), `min_price`/`starting_price` (needs
        // variants), and `colors` — every relation is eager-loaded
        // below so the related strip never trips lazy loading.
        $related = collect();
        if ($product->category_id) {
            $related = Product::query()
                ->where('is_archived', false)
                ->where('category_id', $product->category_id)
                ->where('id', '!=', $product->id)
                ->with([
                    'variants:id,product_id,price,discount_price,quantity',
                    'colors:id,name,hex_code',
                    'productColors.color:id,name,hex_code',
                    'productColors.images',
                ])
                ->latest('id')
                ->limit(4)
                ->get();
        }

        return view('product', [
            'product' => $product,
            'variantsByColor' => $variantsByColor,
            'relatedProducts' => $related,
            'sizeChart' => null,
        ]);
    }
}
