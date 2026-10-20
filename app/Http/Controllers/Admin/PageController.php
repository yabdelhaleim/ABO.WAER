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

    public function index(): View
    {
        $pagesJson = $this->service->getAll()->map(fn (Page $p) => [
            'id' => $p->id,
            'slug' => $p->slug,
            'title' => $p->title,
            'title_ar' => $p->title_ar,
            'content' => $p->content,
            'content_ar' => $p->content_ar,
            'is_active' => $p->is_active,
        ])->toJson();

        return view('admin.pages.index', compact('pagesJson'));
    }

    public function update(PageRequest $request, Page $page): JsonResponse
    {
        $page = $this->service->update($page, $request->validated());

        return response()->json(['success' => true, 'page' => $page]);
    }
}
