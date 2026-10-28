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
     *
     * The payload is built by the service in the shape the screen
     * reads — placement, schedule, look and link already resolved — so
     * the browser never has to guess a field the server owns.
     */
    public function index(): View
    {
        $bannersJson = json_encode(
            $this->service->getAdminPayload(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $targets = $this->service->getLinkTargets();

        $categoriesJson = json_encode($targets['categories'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $productsJson = json_encode($targets['products'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $routesJson = json_encode([
            'store' => route('admin.banners.store'),
            'update' => route('admin.banners.update', ['banner' => '__BANNER__']),
            'duplicate' => route('admin.banners.duplicate', ['banner' => '__BANNER__']),
            'destroy' => route('admin.banners.destroy', ['banner' => '__BANNER__']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return view('admin.banners.index', compact(
            'bannersJson',
            'categoriesJson',
            'productsJson',
            'routesJson'
        ));
    }

    public function store(BannerRequest $request): JsonResponse
    {
        $banner = $this->service->create(
            $request->validated(),
            $request->file('image'),
            $request->file('mobile_image')
        );

        return response()->json([
            'success' => true,
            'message' => 'Banner created.',
            'banner' => $this->service->payload($banner),
        ], 201);
    }

    public function update(BannerRequest $request, Banner $banner): JsonResponse
    {
        $banner = $this->service->update(
            $banner,
            $request->validated(),
            $request->file('image'),
            $request->file('mobile_image')
        );

        return response()->json([
            'success' => true,
            'message' => 'Banner saved.',
            'banner' => $this->service->payload($banner),
        ]);
    }

    /**
     * Copy a banner — same copy, same picture, switched off.
     */
    public function duplicate(Banner $banner): JsonResponse
    {
        $copy = $this->service->duplicate($banner);

        return response()->json([
            'success' => true,
            'message' => 'Banner duplicated.',
            'banner' => $this->service->payload($copy),
        ], 201);
    }

    public function destroy(Banner $banner): JsonResponse
    {
        $this->service->delete($banner);

        return response()->json([
            'success' => true,
            'message' => 'Banner deleted.',
        ]);
    }
}
