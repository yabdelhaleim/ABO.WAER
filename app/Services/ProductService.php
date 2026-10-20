<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    public function __construct(protected ImageService $images) {}

    /**
     * List products for the admin index page, with relations
     * pre-loaded so the view can render counts without N+1.
     */
    public function listForAdmin(): Collection
    {
        return Product::with(['category', 'variants', 'images'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Load a product together with everything needed to render the
     * create / edit form: category, colors, sizes, variants and
     * images grouped by color.
     *
     * @return array{product: Product, categories: Collection, colors: Collection, sizes: Collection}
     */
    public function loadFormData(?Product $product = null): array
    {
        return [
            'product' => $product?->load(['variants', 'colors', 'images']) ?? new Product,
            'categories' => Category::orderBy('sort_order')->orderBy('name')->get(),
            'colors' => Color::orderBy('sort_order')->orderBy('name')->get(),
            'sizes' => Size::orderBy('group')->orderBy('sort_order')->orderBy('name')->get(),
        ];
    }

    /**
     * Create a product together with its pivot colors, images and
     * variants. The whole tree of writes is wrapped in a transaction
     * so a partial upload failure rolls everything back.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $images  indexed by color_id
     */
    public function create(array $data, array $images = []): Product
    {
        return DB::transaction(function () use ($data, $images) {
            $product = Product::create($this->extractProductData($data));

            $this->syncColors($product, $data['colors'] ?? []);
            $this->storeImages($product, $images);
            $this->syncVariants($product, $data['variants'] ?? []);

            return $product->fresh(['variants', 'colors', 'images']);
        });
    }

    /**
     * Update a product and replace any colors, images and variants
     * that are no longer present.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $images  indexed by color_id
     */
    public function update(Product $product, array $data, array $images = []): Product
    {
        return DB::transaction(function () use ($product, $data, $images) {
            $product->update($this->extractProductData($data));

            $this->syncColors($product, $data['colors'] ?? []);
            $this->syncVariants($product, $data['variants'] ?? []);
            $this->storeImages($product, $images);
            $this->pruneDeletedImages($product, $data['removed_images'] ?? []);

            return $product->fresh(['variants', 'colors', 'images']);
        });
    }

    /**
     * Delete a product. Cascade rules at the DB level remove its
     * pivot rows, variants and images; the matching image files
     * are removed from storage afterwards.
     */
    public function delete(Product $product): bool
    {
        $images = $product->images()->get();

        DB::transaction(function () use ($product) {
            $product->delete();
        });

        foreach ($images as $image) {
            $this->images->deleteImage($image->path, $image->thumb_path);
        }

        return true;
    }

    /**
     * Flip the is_archived flag on a product.
     */
    public function toggleArchive(Product $product): Product
    {
        $product->update(['is_archived' => ! $product->is_archived]);

        return $product;
    }

    /**
     * Whitelist the columns that belong to the products table so the
     * caller can safely pass the whole validated array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractProductData(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'category_id',
            'name',
            'name_ar',
            'slug',
            'description',
            'is_archived',
            'is_featured',
        ]));
    }

    /**
     * Replace the product's pivot colors with the supplied list of ids.
     *
     * @param  array<int, int|string>  $colorIds
     */
    protected function syncColors(Product $product, array $colorIds): void
    {
        $product->colors()->sync(array_values(array_unique(array_map('intval', $colorIds))));
    }

    /**
     * Upsert variants keyed by (color_id, size_id). Missing pairs are
     * removed; new ones are inserted. SKUs are derived deterministically
     * from the product slug, color id and size id.
     *
     * @param  array<int, array<string, mixed>>  $variants
     */
    protected function syncVariants(Product $product, array $variants): void
    {
        $incoming = collect($variants)
            ->filter(fn ($v) => isset($v['color_id'], $v['size_id']))
            ->map(function (array $row) use ($product) {
                $colorId = (int) $row['color_id'];
                $sizeId = (int) $row['size_id'];

                return [
                    'product_id' => $product->id,
                    'color_id' => $colorId,
                    'size_id' => $sizeId,
                    'sku' => $this->buildSku($product, $colorId, $sizeId),
                    'price' => (float) ($row['price'] ?? 0),
                    'cost' => (float) ($row['cost'] ?? 0),
                    'discount_price' => isset($row['discount_price']) && $row['discount_price'] !== ''
                        ? (float) $row['discount_price']
                        : null,
                    'quantity' => (int) ($row['quantity'] ?? 0),
                ];
            });

        // Remove variants that no longer appear in the submitted list.
        $incomingKeys = $incoming
            ->map(fn ($row) => $row['color_id'].':'.$row['size_id'])
            ->all();

        $product->variants()
            ->whereNotIn('color_id', $incoming->pluck('color_id')->unique()->all() ?: [0])
            ->orWhereNotIn('size_id', $incoming->pluck('size_id')->unique()->all() ?: [0])
            ->delete();

        // Ensure each incoming row has the unique composite key set for upsert.
        $rows = $incoming->map(fn ($row) => $row + [
            'updated_at' => now(),
            'created_at' => now(),
        ])->all();

        if ($rows !== []) {
            Variant::upsert(
                $rows,
                ['product_id', 'color_id', 'size_id'],
                ['sku', 'cost', 'price', 'discount_price', 'quantity', 'updated_at']
            );
        }
    }

    /**
     * Build a stable SKU for a (product, color, size) triple.
     */
    protected function buildSku(Product $product, int $colorId, int $sizeId): string
    {
        $base = strtoupper(Str::slug($product->slug ?? $product->name ?? 'PROD', ''));

        return sprintf('%s-C%d-S%d', $base ?: 'PROD', $colorId, $sizeId);
    }

    /**
     * Persist any uploaded images, attaching them to the matching
     * product_color pivot row for the supplied color id.
     *
     * @param  array<int|string, UploadedFile|array<int, UploadedFile>>  $images
     */
    protected function storeImages(Product $product, array $images): void
    {
        foreach ($images as $colorId => $files) {
            $colorId = (int) $colorId;
            $pivot = $product->productColors()->firstOrCreate(['color_id' => $colorId]);

            $files = is_array($files) ? $files : [$files];

            foreach ($files as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $stored = $this->images->processUpload($file, 'products');

                $pivot->images()->create([
                    'path' => $stored['path'],
                    'thumb_path' => $stored['thumb_path'],
                    'sort_order' => 0,
                ]);
            }
        }
    }

    /**
     * Remove any product_images rows the caller marked for deletion
     * (and clean up their files).
     *
     * @param  array<int, int>  $imageIds
     */
    protected function pruneDeletedImages(Product $product, array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }

        $images = $product->images()->whereIn('id', $imageIds)->get();

        foreach ($images as $image) {
            $this->images->deleteImage($image->path, $image->thumb_path);
            $image->delete();
        }
    }
}
