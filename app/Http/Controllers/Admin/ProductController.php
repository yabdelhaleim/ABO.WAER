<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Color;
use App\Models\Product;
use App\Models\Variant;
use App\Services\CsvExportService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function __construct(protected ProductService $service) {}

    /**
     * Show the products index with the real Eloquent collection.
     */
    public function index(): View
    {
        $products = $this->service->listForAdmin();

        $payload = $products->map(fn (Product $product) => $this->presentProduct($product))->values();

        return view('admin.products.index', [
            'products' => $products,
            'payload' => $payload,
            'categories' => $this->service->listCategories(),
        ]);
    }

    /**
     * Export the catalog as a CSV file.
     *
     * The export is the same catalog the admin list shows, read straight
     * from the database — an export that disagrees with the screen it
     * was exported from is worse than no export at all.
     *
     * Every cell goes through CsvExportService first: a product whose
     * name begins with "=" would otherwise be run as a formula by the
     * spreadsheet that opens the file.
     */
    public function export(): StreamedResponse
    {
        $products = $this->service->listForAdmin();

        $filename = 'abo-products-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($products) {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['ID', 'Name', 'Name (AR)', 'Slug', 'SKU', 'Category', 'Price', 'Discount Price', 'Cost', 'Stock', 'Variants', 'Status']);

            foreach ($products as $product) {
                $row = $this->presentProduct($product);

                fputcsv($handle, CsvExportService::row([
                    $row['id'],
                    $row['name'],
                    $row['nameAr'],
                    $row['slug'],
                    $row['sku'],
                    $row['category'],
                    $row['price'],
                    $row['discount'] ?? '',
                    $row['cost'],
                    $row['stock'],
                    $row['variantsCount'],
                    $row['status'],
                ]));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Shape one product for the admin list.
     *
     * Every field the list renders travels in this payload: the front
     * end used to answer from a hard-coded array of fourteen sample
     * products, so the table showed products the store could not sell.
     *
     * @return array<string, mixed>
     */
    protected function presentProduct(Product $product): array
    {
        $variants = $product->variants;
        $prices = $variants->map(fn (Variant $variant) => (float) $variant->price);
        $discounts = $variants
            ->filter(fn (Variant $v) => $v->discount_price !== null && (float) $v->discount_price > 0 && (float) $v->discount_price < (float) $v->price)
            ->map(fn (Variant $v) => (float) $v->discount_price);

        return [
            'id' => (string) $product->id,
            'name' => $product->name,
            'nameAr' => $product->name_ar ?? '',
            'slug' => $product->slug,
            'sku' => $variants->first()?->sku ?? '',
            'category' => $product->category?->name ?? '',
            'categoryId' => $product->category_id ? (string) $product->category_id : null,
            'price' => (float) ($prices->min() ?? 0),
            'discount' => $discounts->isEmpty() ? null : (float) $discounts->min(),
            'cost' => (float) ($variants->min('cost') ?? 0),
            'status' => $product->is_archived ? 'archived' : 'active',
            'archived' => (bool) $product->is_archived,
            'active' => ! $product->is_archived,
            'featured' => (bool) $product->is_featured,
            'stock' => (int) $variants->sum('quantity'),
            'low' => ProductService::LOW_STOCK_THRESHOLD,
            'variantsCount' => $variants->count(),
            'colors' => $product->colors
                ->map(fn (Color $color) => [
                    'name' => $color->name,
                    'hex' => $color->hex_code ?? '#8D928A',
                ])
                ->values()
                ->all(),
            'sizes' => $variants
                ->map(fn (Variant $variant) => $variant->size?->name)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'cover' => $product->images->first()?->thumb_url,
        ];
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
