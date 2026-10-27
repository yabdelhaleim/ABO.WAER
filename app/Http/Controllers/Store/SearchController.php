<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Storefront search.
 *
 * The page is server-rendered: the query travels in the URL, the results
 * are read from the products and categories tables, and the same URL can
 * be shared or bookmarked. Nothing is fetched from the browser, so a
 * search works with JavaScript disabled and the results are indexable.
 *
 * The search covers the fields a shopper would type: the product name in
 * both languages, the slug, and the category name. Archived products and
 * hidden categories never appear — a shopper must not find something the
 * store cannot sell.
 */
class SearchController extends Controller
{
    /**
     * The longest term the search looks at. Anything longer is a paste
     * accident, and the database does not need the whole string.
     */
    private const MAX_TERM_LENGTH = 80;

    /**
     * How many products one page of results holds.
     */
    private const PER_PAGE = 12;

    /**
     * The Arabic block of the Unicode range. A term that touches it is an
     * Arabic search, so the result cards answer in Arabic too.
     */
    private const ARABIC_PATTERN = '/[\x{0600}-\x{06FF}]/u';

    /**
     * The results page.
     */
    public function index(Request $request): View
    {
        $term = mb_substr(trim((string) $request->query('q', '')), 0, self::MAX_TERM_LENGTH);

        // The storefront is English by default, so a result card shows the
        // English name. A shopper who searches in Arabic expects to read
        // the results in Arabic, so the card falls back to the Arabic name
        // when the term is Arabic and the product has one.
        $isArabicTerm = $term !== '' && preg_match(self::ARABIC_PATTERN, $term) === 1;

        // The wildcards are escaped so a shopper searching for "50%"
        // gets the products that actually contain "50%" instead of every
        // product. MySQL treats a backslash as the default LIKE escape;
        // on SQLite the escaped wildcard simply matches nothing, which
        // is the safe direction to fail in.
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        $products = Product::query()
            ->where('is_archived', false)
            ->when(
                $term === '',
                // An empty search shows nothing at all — it is not a
                // "browse everything" page.
                fn (Builder $query) => $query->whereKey([]),
                fn (Builder $query) => $query->where(
                    fn (Builder $match) => $match
                        ->where('name', 'like', $like)
                        ->orWhere('name_ar', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                ),
            )
            // The same relations the shop card reads, so the results grid
            // never issues a query per card.
            ->with([
                'category:id,name,slug',
                'colors:id,name,hex_code',
                'variants:id,product_id,price,discount_price,quantity',
                'productColors.color:id,name,hex_code',
                'productColors.images',
            ])
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $categories = $term === ''
            ? collect()
            : Category::query()
                ->where('is_active', true)
                ->where('is_visible', true)
                ->where(
                    fn (Builder $match) => $match
                        ->where('name', 'like', $like)
                        ->orWhere('name_ar', 'like', $like)
                        ->orWhere('slug', 'like', $like)
                )
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

        return view('search', [
            'term' => $term,
            'isArabicTerm' => $isArabicTerm,
            'products' => $products,
            'categories' => $categories,
        ]);
    }
}
