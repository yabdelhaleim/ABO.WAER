<?php

namespace App\Services;

use App\Models\Category;
use App\Models\SizeGuide;
use App\Models\SizeGuideRow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Size guides: a chart is a grid.
 *
 * The rows are the sizes and the columns are the measurements, and the
 * admin names the columns — a pair of pants is measured by waist and
 * hip, a hoodie by chest and sleeve. The guide therefore carries the
 * ordered list of column names and every row carries its values keyed
 * by those names.
 *
 * The storefront reads the same grid: SizeGuideService::chartForCategory
 * hands the modal the columns and the rows, and the modal prints the
 * column names as they are.
 */
class SizeGuideService
{
    /**
     * List every size guide (with rows eager-loaded) ordered by id desc.
     */
    public function listForAdmin(): Collection
    {
        return SizeGuide::with(['category' => fn ($query) => $query->withCount('products'), 'rows'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Create a size guide together with its rows in a single transaction.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function create(array $data, array $rows = []): SizeGuide
    {
        return DB::transaction(function () use ($data, $rows) {
            $guide = SizeGuide::create($this->extractGuideData($data));

            $this->syncRows($guide, $rows);

            return $guide->load(['category', 'rows']);
        });
    }

    /**
     * Update a size guide and replace its rows in a single transaction.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function update(SizeGuide $guide, array $data, array $rows = []): SizeGuide
    {
        return DB::transaction(function () use ($guide, $data, $rows) {
            $guide->update($this->extractGuideData($data));

            $this->syncRows($guide, $rows);

            return $guide->load(['category', 'rows']);
        });
    }

    /**
     * Delete a size guide. Cascade rules at the DB level will remove
     * the matching size_guide_rows rows.
     */
    public function delete(SizeGuide $guide): bool
    {
        return DB::transaction(fn (): bool => (bool) $guide->delete());
    }

    /**
     * Copy a guide and its chart.
     *
     * The copy is inactive and carries no category: it is a draft the
     * admin edits before it goes anywhere near the storefront.
     */
    public function duplicate(SizeGuide $guide): SizeGuide
    {
        return DB::transaction(function () use ($guide) {
            $copy = SizeGuide::create([
                'name' => $guide->name.' (copy)',
                'name_ar' => $guide->name_ar ? $guide->name_ar.' (نسخة)' : null,
                'description' => $guide->description,
                'note' => $guide->note,
                'note_ar' => $guide->note_ar,
                'unit' => $guide->unit,
                'how_to' => $guide->how_to,
                'how_to_ar' => $guide->how_to_ar,
                'image' => $guide->image,
                'measurements' => $guide->measurements,
                'is_active' => false,
            ]);

            foreach ($guide->rows as $row) {
                $copy->rows()->create([
                    'size_name' => $row->size_name,
                    'measurements' => $row->measurements,
                    'sort_order' => $row->sort_order,
                ]);
            }

            return $copy->load(['category', 'rows']);
        });
    }

    /**
     * Show or hide a guide.
     *
     * The storefront only ever reads active guides, so this is what
     * takes a chart off the product page.
     */
    public function toggle(SizeGuide $guide, bool $active): SizeGuide
    {
        $guide->update(['is_active' => $active]);

        return $guide;
    }

    /**
     * The size chart for a category, shaped for the storefront modal.
     *
     * The modal component (resources/views/components/size-guide/modal.blade.php)
     * keys its charts by category slug and reads one row per size with
     * a `measurements` map. This builds exactly that from the `size_guides`
     * and `size_guide_rows` tables.
     *
     * A guide with no measurement rows is not a chart — the modal would
     * render an empty table. Null is returned instead so the modal shows
     * its "measurements coming soon" state rather than an empty grid.
     *
     * @return array{unit: string, note: string, noteAr: string, howTo: string, howToAr: string, types: array<string, array{columns: array<int, string>, rows: array<int, array{size: string, measurements: array<string, float>}>}>, howToMeasure: array<int, array{label: string, hint: string}>}|null
     */
    public function chartForCategory(?Category $category): ?array
    {
        if (! $category) {
            return null;
        }

        $guide = SizeGuide::query()
            ->where('category_id', $category->id)
            ->where('is_active', true)
            ->with('rows')
            ->orderByDesc('id')
            ->first();

        if (! $guide) {
            return null;
        }

        $rows = $guide->rows;

        if ($rows->isEmpty()) {
            return null;
        }

        // The guide names its own columns. A guide written before the
        // grid existed has none, so its columns are read off the rows.
        $columns = $guide->measurements ?? [];

        if ($columns === []) {
            $columns = $this->columnsFromRows($rows);
        }

        return [
            'unit' => $guide->unit ?: 'cm',
            // The admin's own copy travels with the chart: the note is
            // printed under the title and the how-to replaces the
            // generic guidance when the guide has one.
            'note' => (string) ($guide->note ?? ''),
            'noteAr' => (string) ($guide->note_ar ?? ''),
            'howTo' => (string) ($guide->how_to ?? ''),
            'howToAr' => (string) ($guide->how_to_ar ?? ''),
            'types' => [
                $category->slug => [
                    'columns' => $columns,
                    'rows' => $rows->map(fn (SizeGuideRow $row): array => [
                        'size' => (string) $row->size_name,
                        'measurements' => $this->numericMeasurements($row->measurements, $columns),
                    ])->values()->all(),
                ],
            ],
            'howToMeasure' => $this->howToMeasure(),
        ];
    }

    /**
     * The generic "how to measure" guidance the modal renders under the
     * table.
     *
     * This is instructional copy rather than data — it explains what each
     * measurement means, not what this garment measures — so it lives
     * with the component's presentation rather than in the database.
     *
     * @return array<int, array{label: string, hint: string}>
     */
    protected function howToMeasure(): array
    {
        return [
            ['label' => 'A · Chest', 'hint' => 'Measured flat, 1 cm below the armhole.'],
            ['label' => 'B · Length', 'hint' => 'From the highest point of the shoulder to the hem.'],
            ['label' => 'C · Shoulder', 'hint' => 'From one shoulder seam to the other, across the back.'],
            ['label' => 'D · Sleeve', 'hint' => 'From the shoulder seam to the end of the cuff.'],
        ];
    }

    /**
     * Whitelist the columns that belong to the size_guides table so the
     * caller can safely pass the whole validated array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractGuideData(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'category_id',
            'name',
            'name_ar',
            'description',
            'note',
            'note_ar',
            'unit',
            'how_to',
            'how_to_ar',
            'image',
            'measurements',
            'is_active',
        ]));
    }

    /**
     * Synchronize the guide's measurement rows: existing rows not
     * present in the submitted list are removed and incoming ones
     * are inserted (or updated when an `id` is supplied).
     *
     * A row carries its values keyed by the guide's column names:
     * `{"size_name": "M", "measurements": {"Chest": 56, "Length": 72}}`.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function syncRows(SizeGuide $guide, array $rows): void
    {
        $incomingIds = collect($rows)
            ->filter(fn ($row) => isset($row['id']) && $row['id'] !== null && $row['id'] !== '')
            ->map(fn ($row) => (int) $row['id'])
            ->all();

        // Remove rows the caller did not resubmit.
        if ($incomingIds !== []) {
            $guide->rows()->whereNotIn('id', $incomingIds)->delete();
        } else {
            $guide->rows()->delete();
        }

        foreach ($rows as $index => $row) {
            if (! isset($row['size_name'])) {
                continue;
            }

            $payload = [
                'size_guide_id' => $guide->id,
                'size_name' => (string) $row['size_name'],
                'measurements' => $this->numericMeasurements(
                    is_array($row['measurements'] ?? null) ? $row['measurements'] : [],
                ),
                'sort_order' => (int) ($row['sort_order'] ?? $index),
                'updated_at' => now(),
            ];

            if (isset($row['id']) && $row['id'] !== null && $row['id'] !== '') {
                $existing = SizeGuideRow::where('size_guide_id', $guide->id)
                    ->where('id', (int) $row['id'])
                    ->first();

                if ($existing) {
                    $existing->update($payload);

                    continue;
                }
            }

            $payload['created_at'] = now();
            SizeGuideRow::create($payload);
        }
    }

    /**
     * The columns a guide's rows carry, in the order they first appear.
     *
     * @param  Collection<int, SizeGuideRow>  $rows
     * @return array<int, string>
     */
    protected function columnsFromRows(Collection $rows): array
    {
        $columns = [];

        foreach ($rows as $row) {
            foreach (array_keys($row->measurements ?? []) as $name) {
                $columns[$name] = true;
            }
        }

        return array_keys($columns);
    }

    /**
     * A row's measurements as numbers, in the guide's column order.
     *
     * Cells the admin left empty are dropped rather than stored as
     * zeroes, so "not measured" and "measured zero" stay apart.
     *
     * @param  array<string, mixed>|null  $values
     * @param  array<int, string>|null  $columns
     * @return array<string, float>
     */
    protected function numericMeasurements(?array $values, ?array $columns = null): array
    {
        if ($values === null || $values === []) {
            return [];
        }

        $ordered = $columns === null ? array_keys($values) : $columns;
        $result = [];

        foreach ($ordered as $name) {
            if (! array_key_exists($name, $values)) {
                continue;
            }

            $value = $values[$name];

            if ($value === null || $value === '') {
                continue;
            }

            $result[$name] = (float) $value;
        }

        return $result;
    }
}
