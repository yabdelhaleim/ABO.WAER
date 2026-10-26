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
    'announcement' => 'Free shipping over 1,500 EGP · Drop 02 is live',

    'hero' => [
        'eyebrow' => 'URBAN / STREETWEAR / LIFESTYLE',
        'line_1' => 'MODERN FIT.',
        'line_2' => 'URBAN VIBE.',
        'lede' => "Premium men's wear built for the next generation. Style, comfort, attitude.",
        'primary_cta' => ['label' => 'Shop now', 'href' => '/shop'],
        'secondary_cta' => ['label' => 'Explore categories', 'href' => '/shop#categories'],
        'stats' => [
            ['value' => 120, 'suffix' => '+', 'label' => 'Designs'],
            ['value' => 48, 'suffix' => 'h', 'label' => 'Delivery'],
            ['value' => 14, 'suffix' => 'd', 'label' => 'Easy returns'],
        ],
        'photo' => [
            'src' => 'images/streetwear-hero.jpg',
            'alt' => 'ABO brown polo sweatshirt with embroidered DEFINE ABO logo, side profile, lime neon backdrop',
            'width' => 800,
            'height' => 450,
        ],
    ],

    'ticker' => 'NOT MADE TO BLEND IN ✦ BETTER THAN YESTERDAY ✦',

    'categories' => [
        ['name' => 'Hoodies', 'slug' => 'hoodies', 'href' => '/shop?category=hoodies', 'image' => 'images/streetwear-hero.jpg', 'alt' => 'ABO hoodies'],
        ['name' => 'T-Shirts', 'slug' => 't-shirts', 'href' => '/shop?category=t-shirts', 'image' => 'images/streetwear-hero.jpg', 'alt' => 'ABO t-shirts'],
        ['name' => 'Pants', 'slug' => 'pants', 'href' => '/shop?category=pants', 'image' => 'images/streetwear-hero.jpg', 'alt' => 'ABO pants'],
        ['name' => 'Jackets', 'slug' => 'jackets', 'href' => '/shop?category=jackets', 'image' => 'images/streetwear-hero.jpg', 'alt' => 'ABO jackets'],
    ],

    // Placeholder products — easy to replace when the real catalog
    // is ready. No fake prices/inventory are persisted in the DB.
    // Per project policy, sizes and stock are intentionally absent
    // because the data file does not include them — the Shop page
    // hides any UI that depends on those fields.
    'products' => [
        [
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'price' => 1890,
            'badge' => 'NEW',
            'category' => 'hoodies',
            'colors' => ['#0D0F11', '#F4F6F0', '#C6FF00'],
            'image' => 'images/streetwear-hero.jpg',
        ],
        [
            'name' => 'Urban Tee — Black',
            'slug' => 'urban-tee-black',
            'price' => 690,
            'badge' => 'BEST SELLER',
            'category' => 't-shirts',
            'colors' => ['#0D0F11', '#8D928A'],
            'image' => 'images/streetwear-hero.jpg',
        ],
        [
            'name' => 'Cargo Pant — Olive',
            'slug' => 'cargo-pant-olive',
            'price' => 1450,
            'badge' => null,
            'category' => 'pants',
            'colors' => ['#3E3D2B', '#0D0F11'],
            'image' => 'images/streetwear-hero.jpg',
        ],
        [
            'name' => 'Limo Bomber — Lime',
            'slug' => 'limo-bomber-lime',
            'price' => 2790,
            'badge' => 'LIMITED',
            'category' => 'jackets',
            'colors' => ['#C6FF00', '#0D0F11'],
            'image' => 'images/streetwear-hero.jpg',
        ],
    ],

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

    // The footer columns themselves live in
    // resources/views/store/partials/footer.blade.php so every page of
    // the store renders the same set of links. Only the tagline is
    // editorial copy, so only the tagline is defined here.
    'footer' => [
        'tagline' => 'Not made to blend in — premium men\'s streetwear from Cairo.',
    ],
];
