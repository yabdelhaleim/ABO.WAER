<?php

namespace App\Services\Store;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\ShippingSetting;
use App\Models\StoreSetting;

/**
 * The storefront's chrome, measured from the database.
 *
 * The announcement bar, the hero's three figures and the footer's
 * columns used to be a hand-written array in
 * resources/views/store/data/home.php. They claimed things the store
 * had not said: "120+ Designs" while the catalogue held nine products,
 * "Free shipping over 1,500 EGP" whatever the shipping settings said,
 * and a Follow column whose three links went nowhere.
 *
 * Everything here is read from the table that holds it:
 *
 *   - the announcement bar from the live campaign and the shipping
 *     settings;
 *   - the hero's figures from the catalogue and the shipping settings;
 *   - the footer's Shop column from the categories, its Help column
 *     from the published pages, its Follow column from the store's own
 *     social links, and its contact block from the store's details.
 *
 * A store that has not filled a field in does not get a made-up answer:
 * the announcement bar is left out, the Follow column is dropped, and
 * the contact block is hidden.
 */
class StorefrontService
{
    /**
     * How many categories the footer's Shop column lists.
     */
    public const FOOTER_CATEGORY_LIMIT = 6;

    /**
     * The announcement bar's line, or null when the store has nothing
     * to announce.
     *
     * The bar is the one line every visitor sees before anything else,
     * so it has to be true. The admin owns it: an announcement banner
     * in the "announcement" placement is the message, exactly as the
     * banners screen says ("for announcements it's the message text").
     *
     * When the admin has published no announcement the store still has
     * something true to say when it offers free shipping over a
     * threshold, and that is what the bar carries instead.
     */
    public function announcement(): ?string
    {
        $banner = Banner::query()
            ->live()
            ->where('placement', 'announcement')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->first();

        if ($banner) {
            $message = trim((string) ($banner->title ?? ''));

            if ($message !== '') {
                return $message;
            }
        }

        $freeShipping = $this->freeShipping();

        if ($freeShipping['enabled'] && $freeShipping['threshold'] > 0) {
            return 'Free shipping over '.number_format($freeShipping['threshold'], 0).' EGP';
        }

        return null;
    }

    /**
     * The hero's three figures.
     *
     * The hero used to advertise "120+ Designs", "48h Delivery" and
     * "14d Easy returns" — three numbers the store had never been told
     * and could not answer for. These are measured instead:
     *
     *   - how many designs the catalogue holds right now;
     *   - how many categories they are filed under;
     *   - what the shopper pays to have one delivered, or how much they
     *     have to spend for it to arrive free.
     *
     * A store with no shipping settings configured gets two figures
     * rather than a third one made up to fill the row.
     *
     * @return array<int, array{value: string, suffix: string, label: string}>
     */
    public function heroStats(): array
    {
        $stats = [
            [
                'value' => number_format(Product::query()->active()->count()),
                'suffix' => '+',
                'label' => 'Designs',
            ],
            [
                'value' => number_format(Category::query()->where('is_active', true)->count()),
                'suffix' => '',
                'label' => 'Categories',
            ],
        ];

        $freeShipping = $this->freeShipping();

        if ($freeShipping['enabled'] && $freeShipping['threshold'] > 0) {
            $stats[] = [
                'value' => number_format($freeShipping['threshold'], 0),
                'suffix' => ' EGP',
                'label' => 'Free shipping over',
            ];
        } else {
            $fee = (float) ShippingSetting::get('default_fee', 0);

            if ($fee > 0) {
                $stats[] = [
                    'value' => number_format($fee, 0),
                    'suffix' => ' EGP',
                    'label' => 'Delivery',
                ];
            }
        }

        return $stats;
    }

    /**
     * The footer's columns and the store's contact details.
     *
     * @return array<string, mixed>
     */
    public function footer(): array
    {
        return [
            'columns' => array_filter([
                'Shop' => $this->shopLinks(),
                'Help' => $this->helpLinks(),
                'Follow' => $this->socialLinks(),
            ]),
            'contact' => $this->contact(),
        ];
    }

    /**
     * The Shop column: the catalogue and the categories in it.
     *
     * @return array<int, array{label: string, href: string}>
     */
    protected function shopLinks(): array
    {
        $links = [
            ['label' => 'All products', 'href' => route('shop')],
            ['label' => 'New arrivals', 'href' => route('shop', ['sort' => 'newest'])],
            ['label' => 'Sale', 'href' => route('shop', ['sale' => 1])],
        ];

        // The same categories the shop's own sidebar offers: active AND
        // visible. A category the admin has hidden must not come back
        // through the footer as a link into an empty shop page.
        $categories = Category::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->take(self::FOOTER_CATEGORY_LIMIT)
            ->get();

        foreach ($categories as $category) {
            $links[] = [
                'label' => (string) $category->name,
                'href' => route('shop', ['category' => $category->slug]),
            ];
        }

        return $links;
    }

    /**
     * The Help column: the pages the store has published.
     *
     * The five system pages are the only ones the storefront routes
     * answer to, so a page that is not published — or not one of them —
     * has nowhere to point and is left out.
     *
     * @return array<int, array{label: string, href: string}>
     */
    protected function helpLinks(): array
    {
        // The named route each system page is served by.
        $routes = [
            'about' => 'about',
            'contact' => 'contact',
            'shipping' => 'shipping',
            'returns' => 'returns',
            'exchange' => 'exchange',
        ];

        $pages = Page::query()
            ->where('is_active', true)
            ->whereIn('slug', Page::SYSTEM_SLUGS)
            ->orderBy('id')
            ->get();

        $links = [];

        foreach ($pages as $page) {
            $route = $routes[$page->slug] ?? null;

            if ($route === null) {
                continue;
            }

            $links[] = [
                'label' => (string) $page->title,
                'href' => route($route),
            ];
        }

        return $links;
    }

    /**
     * The Follow column: the social accounts the store has given.
     *
     * A column of links that go nowhere is worse than no column, so an
     * account the store has not filled in contributes no link at all.
     *
     * @return array<int, array{label: string, href: string}>
     */
    protected function socialLinks(): array
    {
        $social = StoreSetting::get('store.social', []);
        $social = is_array($social) ? $social : [];

        $networks = [
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'tiktok' => 'TikTok',
        ];

        $links = [];

        foreach ($networks as $key => $label) {
            $url = trim((string) ($social[$key] ?? ''));

            if ($url === '') {
                continue;
            }

            $links[] = [
                'label' => $label,
                'href' => $this->socialUrl($url),
            ];
        }

        return $links;
    }

    /**
     * The store's contact details, as the footer shows them.
     *
     * @return array<string, string>
     */
    public function contact(): array
    {
        return [
            'phone' => trim((string) StoreSetting::get('store.phone', '')),
            'whatsapp' => trim((string) StoreSetting::get('store.whatsapp', '')),
            'email' => trim((string) StoreSetting::get('store.email', '')),
            'address' => trim((string) StoreSetting::get('store.address', '')),
        ];
    }

    /**
     * The minimum order value the shop enforces.
     *
     * The checkout refuses an order below it. The cart has to say so
     * too, or the shopper only finds out at the last step.
     */
    public function minimumOrder(): float
    {
        return (float) StoreSetting::get('orders.minimum', 0);
    }

    /**
     * The free-shipping standing the shipping settings hold.
     *
     * @return array{enabled: bool, threshold: float}
     */
    protected function freeShipping(): array
    {
        return [
            'enabled' => (bool) ShippingSetting::get('free_shipping_enabled', false),
            'threshold' => (float) ShippingSetting::get('free_shipping_threshold', 0),
        ];
    }

    /**
     * A social handle into a URL.
     *
     * A handle the store typed ("instagram.com/abo") is not a URL, so
     * it is given the scheme it needs; a full URL is left alone.
     */
    protected function socialUrl(string $value): string
    {
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        return 'https://'.ltrim($value, '/');
    }
}
