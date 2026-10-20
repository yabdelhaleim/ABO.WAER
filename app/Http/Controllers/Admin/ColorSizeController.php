<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ColorRequest;
use App\Http\Requests\Admin\SizeRequest;
use App\Models\Color;
use App\Models\Size;
use App\Services\ColorSizeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ColorSizeController extends Controller
{
    public function __construct(protected ColorSizeService $service) {}

    /**
     * Show the colors & sizes admin page. The current catalog is
     * passed both as Eloquent collections (for any server-side view
     * logic) and as a JSON script tag the front-end layer can read
     * without re-hitting the database.
     */
    public function index(Request $request): View
    {
        $colors = $this->service->getColors();
        $sizesByGroup = $this->service->getSizesGrouped();

        // Flat list of sizes (still ordered by group / sort_order / name).
        $sizes = $sizesByGroup->flatten();

        $payload = [
            'colors' => $colors->map(fn (Color $color): array => [
                'id' => (string) $color->id,
                'name' => $color->name,
                'nameAr' => $color->name_ar ?? '',
                'hex' => $color->hex_code,
                'active' => (bool) $color->is_active,
                'sortOrder' => (int) $color->sort_order,
            ])->values(),
            'sizes' => $sizes->map(fn (Size $size): array => [
                'id' => (string) $size->id,
                'name' => $size->name,
                'group' => $size->group,
                'active' => (bool) $size->is_active,
                'sortOrder' => (int) $size->sort_order,
            ])->values(),
        ];

        // Globals the page-local JS reads off `window` before boot.
        // Kept as their own JSON blobs so colors-sizes.js can opt into
        // either the flat list (window.__COLORS__) or the grouped shape
        // (window.__SIZE_GROUPS__) without going through the payload.
        $windowColors = $payload['colors'];

        $windowSizeGroups = $sizesByGroup
            ->map(fn (Collection $group): array => $group->map(fn (Size $size): array => [
                'id' => (string) $size->id,
                'name' => $size->name,
                'group' => $size->group,
                'active' => (bool) $size->is_active,
                'sortOrder' => (int) $size->sort_order,
            ])->values()->all())
            ->all();

        return view('admin.colors-sizes.index', [
            'colors' => $colors,
            'sizes' => $sizes,
            'sizesByGroup' => $sizesByGroup,
            'payload' => $payload,
            'payloadJson' => json_encode($payload, JSON_THROW_ON_ERROR),
            'windowColorsJson' => json_encode($windowColors, JSON_THROW_ON_ERROR),
            'windowSizeGroupsJson' => json_encode($windowSizeGroups, JSON_THROW_ON_ERROR),
        ]);
    }

    public function storeColor(ColorRequest $request): JsonResponse
    {
        $color = $this->service->createColor($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Color created successfully.',
            'color' => $color,
        ], 201);
    }

    public function updateColor(ColorRequest $request, Color $color): JsonResponse
    {
        $color = $this->service->updateColor($color, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Color updated successfully.',
            'color' => $color,
        ]);
    }

    public function destroyColor(Color $color): JsonResponse
    {
        $this->service->deleteColor($color);

        return response()->json([
            'success' => true,
            'message' => 'Color deleted successfully.',
        ]);
    }

    public function storeSize(SizeRequest $request): JsonResponse
    {
        $size = $this->service->createSize($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Size created successfully.',
            'size' => $size,
        ], 201);
    }

    public function updateSize(SizeRequest $request, Size $size): JsonResponse
    {
        $size = $this->service->updateSize($size, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Size updated successfully.',
            'size' => $size,
        ]);
    }

    public function destroySize(Size $size): JsonResponse
    {
        $this->service->deleteSize($size);

        return response()->json([
            'success' => true,
            'message' => 'Size deleted successfully.',
        ]);
    }
}
