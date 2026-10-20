<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BannerRequest;
use App\Models\Banner;
use App\Services\BannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class BannerController extends Controller
{
    public function __construct(protected BannerService $service) {}

    /**
     * Show the admin banners index page with the banner list as JSON.
     */
    public function index(): View
    {
        $bannersJson = $this->service->getAll()->map(fn (Banner $b) => [
            'id' => $b->id,
            'title' => $b->title,
            'title_ar' => $b->title_ar,
            'subtitle' => $b->subtitle,
            'subtitle_ar' => $b->subtitle_ar,
            'button_text' => $b->button_text,
            'button_text_ar' => $b->button_text_ar,
            'button_url' => $b->button_url,
            'image_url' => asset('storage/'.$b->image_path),
            'is_active' => (bool) $b->is_active,
            'sort_order' => $b->sort_order,
        ])->toJson();

        return view('admin.banners.index', compact('bannersJson'));
    }

    public function store(BannerRequest $request): JsonResponse
    {
        $banner = $this->service->create(
            $request->validated(),
            $request->file('image')
        );

        return response()->json([
            'success' => true,
            'banner' => $banner,
            'image_url' => asset('storage/'.$banner->image_path),
        ], 201);
    }

    public function update(BannerRequest $request, Banner $banner): JsonResponse
    {
        $banner = $this->service->update(
            $banner,
            $request->validated(),
            $request->file('image')
        );

        return response()->json([
            'success' => true,
            'banner' => $banner,
            'image_url' => asset('storage/'.$banner->image_path),
        ]);
    }

    public function destroy(Banner $banner): JsonResponse
    {
        $this->service->delete($banner);

        return response()->json(['success' => true]);
    }
}
