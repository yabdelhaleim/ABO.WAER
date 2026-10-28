<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Services\Store\StorefrontService;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(protected StorefrontService $storefront) {}

    /**
     * Home page — combines the static editorial copy (hero, ticker,
     * brand statement, why, newsletter, footer) with the live catalog
     * for the "Shop by category" and "New drop" sections.
     *
     * The two catalog sections are DB-only. A card that is not in the
     * database is a card a shopper can click and not buy, so when the
     * admin has published no category or no featured product the page
     * says so instead of falling back to the editorial sample rows.
     */
    public function index(): View
    {
        // 1. Static editorial copy (hero, ticker, banner, why,
        // newsletter, footer) — kept in
        // resources/views/store/data/home.php because those sections are
        // pure presentation with no DB backing.
        $data = require resource_path('views/store/data/home.php');

        // 2. Banners the admin put in the hero slot, switched on and
        //    inside their schedule window, ordered by priority.
        $data['banners'] = Banner::query()
            ->live()
            ->where('placement', 'hero')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        // 3. Categories the admin marked for the homepage strip. Top 8.
        $data['categories'] = Category::query()
            ->where('is_active', true)
            ->where('show_in_homepage', true)
            ->orderBy('sort_order')
            ->take(8)
            ->get();

        // 4. Featured products with every relation the home card template
        // reads eager-loaded (category for the breadcrumb, variants for
        // the starting-price accessor, the through `images` relation for
        // the cover, `colors` + `productColors` + their `color` pivot
        // for the swatches — without this the home page issued one
        // query per product for the swatch fallback).
        $data['featuredProducts'] = Product::query()
            ->active()
            ->where('is_featured', true)
            ->with([
                'category:id,name,slug',
                'variants:id,product_id,price,discount_price,quantity',
                'images',
                'colors:id,name,hex_code',
                'productColors.color:id,name,hex_code',
            ])
            ->latest('id')
            ->take(8)
            ->get();

        // 5. The hero's three figures. They used to be a hand-written
        //    "120+ Designs / 48h Delivery / 14d Easy returns" — numbers
        //    the store had never been told. They are measured now: how
        //    many designs the catalogue holds, how many categories they
        //    are filed under, and what the shopper pays to have one
        //    delivered.
        $data['hero']['stats'] = $this->storefront->heroStats();

        return view('home', $data);
    }
}
