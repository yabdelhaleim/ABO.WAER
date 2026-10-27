<?php

namespace App\Models;

use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    /**
     * The slugs that have a dedicated storefront route.
     *
     * These five are registered by name in routes/web.php (`/about`,
     * `/contact`, `/shipping`, `/returns`, `/exchange`) and are the only
     * slugs App\Services\PageService::findBySlug can resolve to a live
     * storefront URL. The list is the canonical one: the admin editor
     * uses it to decide which rows may have their slug edited, and
     * App\Http\Controllers\SitemapController uses it to decide which
     * rows may be published to the sitemap.
     *
     * @var list<string>
     */
    public const array SYSTEM_SLUGS = ['about', 'contact', 'shipping', 'returns', 'exchange'];

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Whether the page is one of the five system pages.
     *
     * System pages are addressed by a fixed named route, so their slug
     * is part of the storefront contract and must not be edited.
     */
    public function isSystem(): bool
    {
        return in_array($this->slug, self::SYSTEM_SLUGS, true);
    }
}
