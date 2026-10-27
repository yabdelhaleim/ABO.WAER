<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductBulkRequest;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The bulk actions of the admin catalog.
 *
 * The bulk bar used to "work" against the in-memory sample list: it
 * flipped a flag, rotated a category and reported success without a
 * single row being written. This controller performs the action on the
 * selected rows for real.
 */
class ProductBulkController extends Controller
{
    public function __construct(protected ProductService $service) {}

    /**
     * Apply one bulk action to the selected products.
     */
    public function __invoke(ProductBulkRequest $request): JsonResponse
    {
        $ids = array_map('intval', $request->validated()['ids']);
        $action = $request->validated()['action'];

        $count = DB::transaction(function () use ($ids, $action, $request) {
            $products = Product::query()->whereIn('id', $ids)->get();

            if ($action === 'delete') {
                foreach ($products as $product) {
                    $this->service->delete($product);
                }

                return $products->count();
            }

            if ($action === 'category') {
                $categoryId = (int) $request->validated()['category_id'];

                $products->each(fn (Product $product) => $product->update(['category_id' => $categoryId]));

                return $products->count();
            }

            $archived = $action === 'archive';

            $products->each(fn (Product $product) => $product->update(['is_archived' => $archived]));

            return $products->count();
        });

        return response()->json([
            'success' => true,
            'action' => $action,
            'affected' => $count,
            'message' => $this->message($action, $count),
        ]);
    }

    /**
     * The toast the admin sees after the action ran.
     */
    protected function message(string $action, int $count): string
    {
        $label = match ($action) {
            'activate' => 'activated',
            'archive' => 'archived',
            'category' => 'moved',
            'delete' => 'deleted',
            default => 'updated',
        };

        return $count.' product(s) '.$label.'.';
    }
}
