<?php

namespace App\Services;

use App\Models\Color;
use App\Models\Size;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ColorSizeService
{
    /**
     * Return all colors ordered by sort_order (then by name for stability).
     */
    public function getColors(): Collection
    {
        return Color::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Return sizes grouped by `group`, with each group ordered by
     * sort_order (then by name for stability).
     *
     * The product count is the number of distinct products the size
     * appears in — one product can carry the same size in more than
     * one variant.
     */
    public function getSizesGrouped(): Collection
    {
        return Size::query()
            ->withCount([
                'variants as products_count' => fn ($query) => $query
                    ->selectRaw('count(distinct variants.product_id)'),
            ])
            ->orderBy('group')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy('group');
    }

    /**
     * Write the order of the colors.
     *
     * @param  array<int, int>  $colorIds
     */
    public function reorderColors(array $colorIds): void
    {
        DB::transaction(function () use ($colorIds): void {
            foreach (array_values($colorIds) as $index => $colorId) {
                Color::query()->whereKey($colorId)->update(['sort_order' => $index]);
            }
        });
    }

    /**
     * Write the order of the sizes inside their group.
     *
     * @param  array<int, int>  $sizeIds
     */
    public function reorderSizes(array $sizeIds): void
    {
        DB::transaction(function () use ($sizeIds): void {
            foreach (array_values($sizeIds) as $index => $sizeId) {
                Size::query()->whereKey($sizeId)->update(['sort_order' => $index]);
            }
        });
    }

    /**
     * Switch every size of a group on or off at once.
     */
    public function setGroupActive(string $group, bool $active): int
    {
        return Size::query()
            ->where('group', $group)
            ->update(['is_active' => $active]);
    }

    /**
     * Create a new color. When sort_order is missing/empty the next
     * sort_order value (max + 1) is generated to keep manual ordering stable.
     *
     * @param  array<string, mixed>  $data
     */
    public function createColor(array $data): Color
    {
        if (! isset($data['sort_order']) || $data['sort_order'] === null || $data['sort_order'] === '') {
            $data['sort_order'] = (int) Color::max('sort_order') + 1;
        }

        return Color::create($data);
    }

    /**
     * Update an existing color.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateColor(Color $color, array $data): Color
    {
        $color->update($data);

        return $color;
    }

    /**
     * Delete a color. Cascades to related pivot rows and variants via
     * the database foreign-key constraints.
     */
    public function deleteColor(Color $color): void
    {
        $color->delete();
    }

    /**
     * Create a new size. When sort_order is missing/empty the next
     * sort_order value (per-group max + 1) is generated.
     *
     * @param  array<string, mixed>  $data
     */
    public function createSize(array $data): Size
    {
        if (! isset($data['sort_order']) || $data['sort_order'] === null || $data['sort_order'] === '') {
            $group = $data['group'] ?? 'tops';
            $data['sort_order'] = (int) Size::where('group', $group)->max('sort_order') + 1;
        }

        return Size::create($data);
    }

    /**
     * Update an existing size.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSize(Size $size, array $data): Size
    {
        $size->update($data);

        return $size;
    }

    /**
     * Delete a size. Cascades to related variants via the database
     * foreign-key constraints.
     */
    public function deleteSize(Size $size): void
    {
        $size->delete();
    }
}
