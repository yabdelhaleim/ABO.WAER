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
            'colors' => $colors->map(fn (Color $color): array => $this->colorPayload($color))->values(),
            'sizes' => $sizes->map(fn (Size $size): array => $this->sizePayload($size))->values(),
        ];

        // Globals the page-local JS reads off `window` before boot.
        // Kept as their own JSON blobs so colors-sizes.js can opt into
        // either the flat list (window.__COLORS__) or the grouped shape
        // (window.__SIZE_GROUPS__) without going through the payload.
        $windowColors = $payload['colors'];

        $windowSizeGroups = $sizesByGroup
            ->map(fn (Collection $group): array => $group
                ->map(fn (Size $size): array => $this->sizePayload($size))
                ->values()
                ->all())
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

    /**
     * The color as the screen reads it.
     *
     * @return array<string, mixed>
     */
    protected function colorPayload(Color $color): array
    {
        return [
            'id' => (string) $color->id,
            'name' => $color->name,
            'nameAr' => $color->name_ar ?? '',
            'hex' => $color->hex_code,
            'products' => (int) ($color->products_count ?? 0),
            'active' => (bool) $color->is_active,
            'sortOrder' => (int) $color->sort_order,
        ];
    }

    /**
     * The size as the screen reads it.
     *
     * @return array<string, mixed>
     */
    protected function sizePayload(Size $size): array
    {
        return [
            'id' => (string) $size->id,
            'name' => $size->name,
            'group' => $size->group,
            'products' => (int) ($size->products_count ?? 0),
            'active' => (bool) $size->is_active,
            'sortOrder' => (int) $size->sort_order,
        ];
    }

    public function storeColor(ColorRequest $request): JsonResponse
    {
        $color = $this->service->createColor($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Color created successfully.',
            'color' => $this->colorPayload($color),
        ], 201);
    }

    public function updateColor(ColorRequest $request, Color $color): JsonResponse
    {
        $color = $this->service->updateColor($color, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Color updated successfully.',
            'color' => $this->colorPayload($color),
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

    /**
     * Switch a color on or off.
     */
    public function toggleColor(Request $request, Color $color): JsonResponse
    {
        $color->update(['is_active' => ! $color->is_active]);

        return response()->json([
            'success' => true,
            'message' => $color->is_active
                ? 'Color activated successfully.'
                : 'Color deactivated successfully.',
            'color' => $this->colorPayload($color),
        ]);
    }

    /**
     * Write the order of the colors.
     */
    public function reorderColors(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'colors' => ['required', 'array', 'min:1', 'max:200'],
            'colors.*' => ['integer', 'distinct', 'exists:colors,id'],
        ]);

        $this->service->reorderColors($validated['colors']);

        return response()->json([
            'success' => true,
            'message' => 'Color order updated.',
            'colors' => $this->service->getColors()
                ->map(fn (Color $color): array => $this->colorPayload($color))
                ->values()
                ->all(),
        ]);
    }

    public function storeSize(SizeRequest $request): JsonResponse
    {
        $size = $this->service->createSize($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Size created successfully.',
            'size' => $this->sizePayload($size),
        ], 201);
    }

    public function updateSize(SizeRequest $request, Size $size): JsonResponse
    {
        $size = $this->service->updateSize($size, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Size updated successfully.',
            'size' => $this->sizePayload($size),
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

    /**
     * Write the order of the sizes inside their group.
     */
    public function reorderSizes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sizes' => ['required', 'array', 'min:1', 'max:200'],
            'sizes.*' => ['integer', 'distinct', 'exists:sizes,id'],
        ]);

        $this->service->reorderSizes($validated['sizes']);

        return response()->json([
            'success' => true,
            'message' => 'Size order updated.',
            'sizes' => $this->service->getSizesGrouped()
                ->flatten()
                ->map(fn (Size $size): array => $this->sizePayload($size))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Switch every size of a group on or off at once.
     *
     * The groups are not rows of their own — they are the `group`
     * column of the sizes — so the switch writes every size the group
     * holds.
     */
    public function toggleSizeGroup(Request $request, string $group): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $active = (bool) $validated['is_active'];

        $count = $this->service->setGroupActive($group, $active);

        return response()->json([
            'success' => true,
            'message' => $active
                ? 'Size group shown in store.'
                : 'Size group hidden from store.',
            'group' => $group,
            'updated' => $count,
            'sizes' => $this->service->getSizesGrouped()
                ->flatten()
                ->map(fn (Size $size): array => $this->sizePayload($size))
                ->values()
                ->all(),
        ]);
    }
}
