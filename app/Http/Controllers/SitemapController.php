<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Http\Response;

/**
 * Builds /sitemap.xml for search-engine crawlers.
 *
 * The document is rendered by resources/views/sitemap.blade.php rather than
 * a sitemap package so the project keeps its zero-extra-dependency rule and
 * the markup stays reviewable next to the rest of the Blade templates.
 * Every value goes through Blade's `{{ }}` escaping, which turns `&` into
 * `&amp;` — exactly what the sitemap schema expects for the category query
 * string, so no manual htmlspecialchars() is needed.
 *
 * Only shopper-visible rows are listed. Products are filtered through
 * Product::active() (is_archived = false) and categories through
 * Category::active() (is_active = true) — the same scopes the catalog and
 * the sitemap have to agree on. Archived or unpublished URLs never reach the
 * document, so Google does not index a page that then answers 404.
 */
class SitemapController extends Controller
{
    /**
     * Content pages that have a dedicated named route in routes/web.php.
     *
     * Those routes are registered as /about, /contact, /shipping, /returns
     * and /exchange, so each page's slug doubles as its route name and
     * `route($page->slug)` resolves the absolute URL. The whitelist exists
     * because Page rows are free-form: an admin who adds a "faq" row would
     * otherwise make route() throw and take the whole sitemap down with it.
     *
     * @var list<string>
     */
    protected const array CONTENT_ROUTES = ['about', 'contact', 'shipping', 'returns', 'exchange'];

    /**
     * Render the sitemap as application/xml.
     *
     * Each query selects only the columns the template reads (slug +
     * updated_at) and adds a deterministic order so repeated crawls produce
     * byte-identical documents. Google re-fetches sitemaps frequently; a
     * stable ordering keeps its diffing cheap.
     */
    public function index(): Response
    {
        $products = Product::active()
            ->select(['slug', 'updated_at'])
            ->orderBy('id')
            ->get();

        $categories = Category::active()
            ->select(['slug', 'updated_at'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $pages = Page::query()
            ->where('is_active', true)
            ->whereIn('slug', self::CONTENT_ROUTES)
            ->select(['slug', 'updated_at'])
            ->orderBy('id')
            ->get();

        return response()
            ->view('sitemap', compact('products', 'categories', 'pages'))
            ->header('Content-Type', 'application/xml');
    }
}
