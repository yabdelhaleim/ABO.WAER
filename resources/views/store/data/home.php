<?php

/*
 * Single source of truth for the ABO home + shop copy, categories, and
 * product cards. Per the master spec (section 10.1) all editable
 * content lives in one place so the client can swap placeholder text
 * for real product data later.
 *
 * Placeholder images use the public/images/hero photo (which the
 * client already has) — no fake product photos are invented.
 *
 * NOTE: Per project policy (no backend), product data lives in this
 * data file rather than a database. The Shop page reads this file
 * directly via a closure route in routes/web.php.
 */

return [
    // The announcement bar is NOT here. It is measured: the live
    // campaign and the free-shipping threshold the shipping settings
    // hold (see App\Services\Store\StorefrontService). It used to say
    // "Free shipping over 1,500 EGP · Drop 02 is live" on every page of
    // every store, whether or not that was true of them.

    'hero' => [
        'eyebrow' => 'URBAN / STREETWEAR / LIFESTYLE',
        'line_1' => 'MODERN FIT.',
        'line_2' => 'URBAN VIBE.',
        'lede' => "Premium men's wear built for the next generation. Style, comfort, attitude.",
        'primary_cta' => ['label' => 'Shop now', 'href' => '/shop'],
        'secondary_cta' => ['label' => 'Explore categories', 'href' => '/shop#categories'],
        // The hero's three figures are measured too — how many designs
        // the catalogue holds, how many categories they are filed
        // under, and what the shopper pays to have one delivered. The
        // old ones (120+ Designs, 48h Delivery, 14d Easy returns) were
        // numbers the store had never been told. Store\HomeController
        // fills this in from the database.
        'stats' => [],
        'photo' => [
            'src' => 'images/streetwear-hero.jpg',
            'alt' => 'ABO brown polo sweatshirt with embroidered DEFINE ABO logo, side profile, lime neon backdrop',
            'width' => 800,
            'height' => 450,
        ],
    ],

    'ticker' => 'NOT MADE TO BLEND IN ✦ BETTER THAN YESTERDAY ✦',

    // The catalog is NOT part of this file. The home page reads its
    // categories and its featured products from the database (see
    // Store\HomeController), and the storefront pages that used to carry
    // a static product list now read the same live catalog. Keeping a
    // second copy of the catalog here is how the storefront ended up
    // showing products it could not sell.
    //
    // What remains below is the editorial chrome: the copy that has no
    // database table behind it.

    'banner' => [
        'eyebrow' => 'STREET ROOTS · GLOBAL CUT',
        'line_1' => 'BETTER THAN',
        'line_2' => 'YESTERDAY.',
        'subline' => 'Designed in Cairo. Built for the world.',
    ],

    'why' => [
        ['n' => '01', 'title' => 'Premium fabric', 'body' => 'Heavyweight cotton, brushed fleece, tight weaves.'],
        ['n' => '02', 'title' => 'Modern fit', 'body' => 'Cut for movement, tested by the people who wear it.'],
        ['n' => '03', 'title' => 'Fast delivery', 'body' => '48h dispatch inside Egypt. Tracked from door to door.'],
        ['n' => '04', 'title' => 'Easy returns', 'body' => '14-day no-questions returns. Free in Cairo.'],
    ],

    'newsletter' => [
        'eyebrow' => 'JOIN THE LIST',
        'headline' => 'Get first access to every drop',
        'subline' => "Once a month. No spam. Just what's new and what's next.",
        'placeholder' => 'you@domain.com',
        'cta' => 'Subscribe',
        'success' => "YOU'RE IN ✓",
    ],

    'footer' => [
        'tagline' => 'Not made to blend in — premium men\'s streetwear from Cairo.',
        // The footer's COLUMNS are not here either. They are measured:
        // the categories the admin published, the pages the store has
        // published, and the social accounts it has given. See
        // App\Services\Store\StorefrontService::footer().
    ],
];
