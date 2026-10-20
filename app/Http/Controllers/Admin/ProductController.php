<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(protected ProductService $service) {}

    /**
     * Show the products index with the real Eloquent collection.
     */
    public function index(): View
    {
        $products = $this->service->listForAdmin();

        $payload = $products->map(fn (Product $product) => [
            'id' => (string) $product->id,
            'name' => $product->name,
            'nameAr' => $product->name_ar ?? '',
            'slug' => $product->slug,
            'category' => $product->category?->name ?? '',
            'categoryId' => $product->category_id ? (string) $product->category_id : null,
            'price' => (float) ($product->variants->min('price') ?? 0),
            'variantsCount' => $product->variants->count(),
            'stock' => (int) $product->variants->sum('quantity'),
            'active' => ! $product->is_archived,
            'archived' => (bool) $product->is_archived,
            'featured' => (bool) $product->is_featured,
            'cover' => $product->images->first()?->thumb_path
                ? asset('storage/'.$product->images->first()->thumb_path)
                : null,
        ])->values();

        return view('admin.products.index', [
            'products' => $products,
            'payload' => $payload,
            'payloadJson' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    public function create(): View
    {
        return view('admin.products.form', $this->service->loadFormData() + [
            'mode' => 'create',
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse|JsonResponse
    {
        $product = $this->service->create($request->validated(), $request->file('images') ?? []);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Product created successfully.',
                'product' => $product,
            ], 201);
        }

        return redirect()->route('admin.products.index')
            ->with('success', 'Product created successfully.');
    }

    public function edit(Product $product): View
    {
        return view('admin.products.form', $this->service->loadFormData($product) + [
            'mode' => 'edit',
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse|JsonResponse
    {
        $product = $this->service->update($product, $request->validated(), $request->file('images') ?? []);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully.',
                'product' => $product,
            ]);
        }

        return redirect()->route('admin.products.index')
            ->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->service->delete($product);

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
    }

    public function toggleArchive(Product $product): JsonResponse
    {
        $product = $this->service->toggleArchive($product);

        return response()->json([
            'success' => true,
            'message' => 'Product archive flag updated.',
            'product' => $product,
        ]);
    }
}
