<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Home page — combines the static editorial copy (hero, ticker,
     * brand statement, why, newsletter, footer) with the live catalog
     * for the "Shop by category" and "New drop" sections.
     */
    public function index(): View
    {
        // 1. Static editorial copy (announcement, hero, ticker, banner,
        // why, newsletter, footer) — kept in resources/views/store/data/home.php
        // because those sections are pure presentation with no DB backing.
        $data = require resource_path('views/store/data/home.php');

        // Keep the static category array as a Blade-level fallback so the
        // grid keeps rendering its 4 cards before the admin has marked any
        // category as "show_in_homepage".
        $data['legacyCategories'] = $data['categories'] ?? [];

        // Keep the static product array as a Blade-level fallback for the
        // "New drop" strip. Same rationale as legacyCategories — the
        // grid needs to look complete with zero DB rows.
        $data['legacyProducts'] = $data['products'] ?? [];

        // 2. Active banners ordered by priority, then most recent.
        $data['banners'] = Banner::query()
            ->where('is_active', true)
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

        return view('home', $data);
    }
}
