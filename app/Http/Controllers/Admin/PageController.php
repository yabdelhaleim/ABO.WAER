<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PageRequest;
use App\Models\Page;
use App\Services\PageService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class PageController extends Controller
{
    public function __construct(protected PageService $service) {}

    /**
     * The admin pages list — DB-driven.
     *
     * The payload is built from the `pages` table only; there is no
     * static sample array any more. Its shape is the contract the
     * page-local editor (public/admin/js/pages.js) hydrates from, and
     * every key maps to a real column:
     *
     *   title.en    → pages.title
     *   title.ar    → pages.title_ar
     *   content.en  → pages.content
     *   content.ar  → pages.content_ar
     *   status      → pages.is_active (true = published, false = draft)
     *   updated     → pages.updated_at
     *   slug        → pages.slug
     *   type        → derived: "system" for the five fixed storefront
     *                 routes (Page::SYSTEM_SLUGS), "custom" otherwise
     *
     * The editor deliberately exposes no SEO / contact-details /
     * show-in-footer fields: the `pages` table has no column to store
     * them, so offering them in the UI would silently drop whatever
     * the admin typed. Adding them back needs a migration first.
     *
     * The JSON is encoded with JSON_UNESCAPED_UNICODE so the Arabic
     * titles stay readable, and with the JSON_HEX_* flags so an admin
     * copy containing `</script>` cannot break out of the
     * <script type="application/json"> tag the view renders it in.
     */
    public function index(): View
    {
        $pagesJson = $this->service->getAll()->map(fn (Page $p) => [
            'id' => $p->id,
            'slug' => $p->slug,
            'type' => $p->isSystem() ? 'system' : 'custom',
            'status' => $p->is_active ? 'published' : 'draft',
            'title' => [
                'en' => $p->title,
                'ar' => $p->title_ar,
            ],
            'content' => [
                'en' => $p->content,
                'ar' => $p->content_ar,
            ],
            'updated' => $p->updated_at?->toIso8601String(),
            // The endpoint the editor PUTs to. Shipped with the row so
            // the page-local script never has to guess the URL.
            'updateUrl' => route('admin.pages.update', $p),
        ])->toJson(JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        return view('admin.pages.index', compact('pagesJson'));
    }

    /**
     * Persist an edited page.
     *
     * Only the fields App\Http\Requests\Admin\PageRequest validates are
     * written — `slug` is deliberately absent from that list, so a
     * system page's URL can never be repointed at a different route
     * through this endpoint.
     */
    public function update(PageRequest $request, Page $page): JsonResponse
    {
        $page = $this->service->update($page, $request->validated());

        return response()->json(['success' => true, 'page' => $page]);
    }
}
