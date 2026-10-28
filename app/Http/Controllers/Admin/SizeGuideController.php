<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Size;
use App\Models\SizeGuide;
use App\Services\ImageService;
use App\Services\SizeGuideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;

/**
 * Admin size guides screen.
 *
 * The screen is a grid editor: the rows are the sizes, the columns are
 * the measurements, and the admin names the columns. The payload this
 * controller builds is the shape the editor draws — the guide's fields,
 * the ordered column names and the grid itself — so the browser never
 * has to guess how a chart is put together.
 */
class SizeGuideController extends Controller
{
    public function __construct(
        protected SizeGuideService $service,
        protected ImageService $images,
    ) {}

    /**
     * Show the size guides index page with the real list.
     */
    public function index(): View
    {
        $guides = $this->service->listForAdmin();

        return view('admin.size-guides.index', [
            'guides' => $guides,
            'payload' => $guides->map(fn (SizeGuide $guide): array => $this->payload($guide))->values(),
            'categories' => Category::orderBy('sort_order')->orderBy('name')->get(),
            // The size palette the editor offers: the sizes the shop
            // actually sells, not a list written into the view.
            'sizes' => Size::query()
                ->where('is_active', true)
                ->orderBy('group')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name')
                ->unique()
                ->values(),
            'routes' => [
                'store' => route('admin.size-guides.store'),
                'update' => route('admin.size-guides.update', ['sizeGuide' => '__GUIDE__']),
                'destroy' => route('admin.size-guides.destroy', ['sizeGuide' => '__GUIDE__']),
                'toggle' => route('admin.size-guides.toggle', ['sizeGuide' => '__GUIDE__']),
                'duplicate' => route('admin.size-guides.duplicate', ['sizeGuide' => '__GUIDE__']),
                'image' => route('admin.size-guides.image', ['sizeGuide' => '__GUIDE__']),
            ],
        ]);
    }

    /**
     * Create a size guide.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $rows = $this->extractRows($request);

        $guide = $this->service->create($data, $rows);

        return response()->json([
            'success' => true,
            'message' => 'Size guide created successfully.',
            'guide' => $this->payload($guide),
        ], 201);
    }

    /**
     * Update a size guide.
     */
    public function update(Request $request, SizeGuide $sizeGuide): JsonResponse
    {
        $data = $this->validated($request);
        $rows = $this->extractRows($request);

        $guide = $this->service->update($sizeGuide, $data, $rows);

        return response()->json([
            'success' => true,
            'message' => 'Size guide updated successfully.',
            'guide' => $this->payload($guide),
        ]);
    }

    public function destroy(SizeGuide $sizeGuide): JsonResponse
    {
        try {
            $this->service->delete($sizeGuide);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to delete size guide.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Size guide deleted successfully.',
        ]);
    }

    /**
     * Show or hide a guide.
     *
     * The storefront only reads active guides, so this is what takes a
     * chart off the product page.
     */
    public function toggle(Request $request, SizeGuide $sizeGuide): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $this->service->toggle($sizeGuide, (bool) $validated['is_active']);

        return response()->json([
            'success' => true,
            'message' => 'Size guide updated successfully.',
            'guide' => $this->payload($sizeGuide->fresh()),
        ]);
    }

    /**
     * Copy a guide and its chart.
     */
    public function duplicate(SizeGuide $sizeGuide): JsonResponse
    {
        $copy = $this->service->duplicate($sizeGuide);

        return response()->json([
            'success' => true,
            'message' => 'Size guide duplicated successfully.',
            'guide' => $this->payload($copy),
        ], 201);
    }

    /**
     * Store the guide's how-to image.
     *
     * The image is a request of its own: the guide has to exist
     * before it can carry a file, and a file that fails to upload
     * should not take the guide's edits down with it.
     */
    public function image(Request $request, SizeGuide $sizeGuide): JsonResponse
    {
        $request->validate([
            'image_file' => ['required', 'image', 'max:4096'],
        ]);

        $upload = $request->file('image_file');

        $sizeGuide->update([
            'image' => $this->images->processUpload($upload, 'size-guides')['path'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully.',
            'guide' => $this->payload($sizeGuide->fresh()),
        ]);
    }

    /**
     * One guide, in the shape the editor draws.
     *
     * @return array<string, mixed>
     */
    protected function payload(SizeGuide $guide): array
    {
        $guide->loadMissing(['category', 'rows']);

        $columns = $guide->measurements ?? [];

        $rows = $guide->rows->map(function ($row) use ($columns): array {
            $values = $row->measurements ?? [];

            return [
                'id' => (string) $row->id,
                'size_name' => (string) $row->size_name,
                'measurements' => array_map(
                    fn (mixed $value): string => $value === null ? '' : (string) $value,
                    array_intersect_key($values, array_flip($columns)),
                ),
            ];
        })->values()->all();

        return [
            'id' => (string) $guide->id,
            'name' => (string) $guide->name,
            'name_ar' => (string) ($guide->name_ar ?? ''),
            'description' => (string) ($guide->description ?? ''),
            'note' => (string) ($guide->note ?? ''),
            'note_ar' => (string) ($guide->note_ar ?? ''),
            'unit' => $guide->unit ?: 'cm',
            'how_to' => (string) ($guide->how_to ?? ''),
            'how_to_ar' => (string) ($guide->how_to_ar ?? ''),
            'image' => ImageService::publicUrl($guide->image),
            'image_path' => (string) ($guide->image ?? ''),
            'category_id' => $guide->category_id ? (string) $guide->category_id : null,
            'product_count' => (int) ($guide->category?->products_count ?? 0),
            'active' => (bool) $guide->is_active,
            // The grid: the column names, the sizes and the values.
            'measurements' => $columns,
            'sizes' => $guide->rows->map(fn ($row): string => (string) $row->size_name)->values()->all(),
            'rows' => $rows,
        ];
    }

    /**
     * The validated fields of a guide.
     *
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        $data = Validator::make($request->all(), $this->rules())->validate();

        // The path of the image that is already stored. An empty
        // string takes the image away; a file is uploaded through the
        // image endpoint, which needs a guide to hold it.
        if ($request->has('image')) {
            $data['image'] = $request->input('image') ?: null;
        }

        return $data;
    }

    /**
     * Validation rules used for both create and update.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'note_ar' => ['nullable', 'string'],
            'unit' => ['nullable', 'string', 'in:cm,inch'],
            'how_to' => ['nullable', 'string'],
            'how_to_ar' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:255'],
            'measurements' => ['nullable', 'array', 'max:6'],
            'measurements.*' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'rows' => ['nullable', 'array'],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.size_name' => ['required', 'string', 'max:50'],
            'rows.*.measurements' => ['nullable', 'array'],
            'rows.*.measurements.*' => ['nullable', 'numeric'],
        ];
    }

    /**
     * Pull the normalized row payload off the request. Accepts either
     * a flat `rows` array or a `size_guide_rows` nested key.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractRows(Request $request): array
    {
        $rows = $request->input('rows');

        if (! is_array($rows)) {
            $rows = $request->input('size_guide_rows', []);
        }

        return is_array($rows) ? array_values($rows) : [];
    }
}
