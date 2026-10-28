<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryService $categoryService
    ) {}

    /**
     * عرض شاشة الأقسام
     */
    public function index(): View
    {
        $categories = $this->categoryService->getAll();

        $categoriesJson = $categories->map(fn (Category $cat) => $this->payload($cat));

        return view('admin.categories.index', compact('categories', 'categoriesJson'));
    }

    /**
     * The category as the screen reads it.
     *
     * The screen matches rows by their string id, so the shape it draws
     * is the one the server sends — the browser never rebuilds it.
     *
     * @return array<string, mixed>
     */
    protected function payload(Category $category): array
    {
        return [
            'id' => (string) $category->id,
            'parentId' => $category->parent_id ? (string) $category->parent_id : null,
            'name' => $category->name,
            'nameAr' => $category->name_ar ?? '',
            'slug' => $category->slug,
            'description' => $category->description ?? '',
            'products' => (int) ($category->products_count ?? 0),
            'active' => (bool) $category->is_active,
            'visibleInStore' => (bool) $category->is_visible,
            'showInHomepage' => (bool) $category->show_in_homepage,
            'cover' => $category->image_url,
        ];
    }

    /**
     * حفظ قسم جديد
     */
    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create(
            $request->validated(),
            $request->file('image')
        );

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully.',
            'category' => $this->payload($category),
        ], 201);
    }

    /**
     * تعديل بيانات قسم
     */
    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        $category = $this->categoryService->update(
            $category,
            $request->validated(),
            $request->file('image'),
            $request->boolean('remove_image')
        );

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully.',
            'category' => $this->payload($category),
        ]);
    }

    /**
     * Switch a category on or off.
     */
    public function toggle(Request $request, Category $category): JsonResponse
    {
        $category->update(['is_active' => ! $category->is_active]);

        return response()->json([
            'success' => true,
            'message' => $category->is_active
                ? 'Category activated successfully.'
                : 'Category deactivated successfully.',
            'category' => $this->payload($category),
        ]);
    }

    /**
     * Write the order and the nesting of the category tree.
     *
     * The screen sends the tree the way it draws it: every category
     * with the parent it sits under and its place among its siblings.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'categories' => ['required', 'array', 'min:1', 'max:500'],
            'categories.*.id' => ['required', 'integer', 'exists:categories,id'],
            'categories.*.parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'categories.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $rows = $validated['categories'];

        foreach ($rows as $row) {
            if ($row['parent_id'] === null) {
                continue;
            }

            $parentId = (int) $row['parent_id'];
            $categoryId = (int) $row['id'];

            // A category cannot sit under itself or under one of its
            // own children — the tree would fold in on itself.
            if ($parentId === $categoryId || $this->isDescendant($parentId, $categoryId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A category cannot be moved under itself.',
                ], 422);
            }
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                Category::query()->whereKey($row['id'])->update([
                    'parent_id' => $row['parent_id'],
                    'sort_order' => $row['sort_order'],
                ]);
            }
        });

        $categories = $this->categoryService->getAll()
            ->map(fn (Category $cat) => $this->payload($cat))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'Category order updated.',
            'categories' => $categories,
        ]);
    }

    /**
     * Whether a category sits under another one.
     */
    protected function isDescendant(int $possibleDescendant, int $ancestor): bool
    {
        $current = Category::query()->find($possibleDescendant);

        while ($current && $current->parent_id) {
            if ((int) $current->parent_id === $ancestor) {
                return true;
            }

            $current = Category::query()->find($current->parent_id);
        }

        return false;
    }

    /**
     * حذف قسم
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->categoryService->delete($category);

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully.',
        ]);
    }
}
