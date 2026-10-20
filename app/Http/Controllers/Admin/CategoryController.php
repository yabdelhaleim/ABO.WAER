<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
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

        $categoriesJson = $categories->map(function ($cat) {
            return [
                'id' => (string) $cat->id,
                'parentId' => $cat->parent_id ? (string) $cat->parent_id : null,
                'name' => $cat->name,
                'nameAr' => $cat->name_ar ?? '',
                'slug' => $cat->slug,
                'description' => $cat->description ?? '',
                'products' => 0,
                'active' => (bool) $cat->is_active,
                'visibleInStore' => (bool) $cat->is_visible,
                'showInHomepage' => (bool) $cat->show_in_homepage,
                'cover' => $cat->image ? asset('storage/'.$cat->image) : null,
            ];
        });

        return view('admin.categories.index', compact('categories', 'categoriesJson'));
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
            'category' => $category,
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
            'category' => $category,
        ]);
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
