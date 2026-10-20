<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\SizeGuide;
use App\Services\SizeGuideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;

class SizeGuideController extends Controller
{
    public function __construct(protected SizeGuideService $service) {}

    /**
     * Show the size guides index page with the real list.
     */
    public function index(): View
    {
        $guides = $this->service->listForAdmin();

        $payload = $guides->map(function (SizeGuide $guide) {
            return [
                'id' => (string) $guide->id,
                'name' => $guide->name,
                'nameAr' => $guide->name_ar ?? '',
                'categoryId' => $guide->category_id ? (string) $guide->category_id : null,
                'description' => $guide->description ?? '',
                'active' => (bool) $guide->is_active,
                'rows' => $guide->rows->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'size' => $row->size_name,
                    'chest' => (float) $row->chest,
                    'length' => (float) $row->length,
                    'sleeve' => $row->sleeve !== null ? (float) $row->sleeve : null,
                    'shoulder' => $row->shoulder !== null ? (float) $row->shoulder : null,
                ])->values(),
            ];
        })->values();

        return view('admin.size-guides.index', [
            'guides' => $guides,
            'payload' => $payload,
            'payloadJson' => json_encode($payload, JSON_THROW_ON_ERROR),
            'categories' => Category::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = Validator::make($request->all(), $this->rules())->validate();
        $rows = $this->extractRows($request);

        $guide = $this->service->create($data, $rows);

        return response()->json([
            'success' => true,
            'message' => 'Size guide created successfully.',
            'guide' => $guide,
        ], 201);
    }

    public function update(Request $request, SizeGuide $sizeGuide): JsonResponse
    {
        $data = Validator::make($request->all(), $this->rules())->validate();
        $rows = $this->extractRows($request);

        $guide = $this->service->update($sizeGuide, $data, $rows);

        return response()->json([
            'success' => true,
            'message' => 'Size guide updated successfully.',
            'guide' => $guide,
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
            'is_active' => ['nullable', 'boolean'],
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
