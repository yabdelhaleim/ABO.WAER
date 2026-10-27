<?php

namespace App\Services;

use App\Models\Category;
use App\Models\SizeGuide;
use App\Models\SizeGuideRow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SizeGuideService
{
    /**
     * List every size guide (with rows eager-loaded) ordered by id desc.
     */
    public function listForAdmin(): Collection
    {
        return SizeGuide::with(['category', 'rows'])
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
     * The size chart for a category, shaped for the storefront modal.
     *
     * The modal component (resources/views/components/size-guide/modal.blade.php)
     * keys its charts by category slug and reads one row per size with a
     * `measurements` map. This builds exactly that from the `size_guides`
     * and `size_guide_rows` tables.
     *
     * A guide with no measurement rows is not a chart — the modal would
     * render an empty table. Null is returned instead so the modal shows
     * its "measurements coming soon" state rather than an empty grid.
     *
     * The measurement columns are derived from the data: `chest` and
     * `length` are always present (the column is NOT NULL), while
     * `sleeve` and `shoulder` only join the table when at least one row
     * actually carries a value.
     *
     * @return array{unit: string, types: array<string, array{columns: array<int, string>, rows: array<int, array{size: string, measurements: array<string, float>}>}>, howToMeasure: array<int, array{label: string, hint: string}>}|null
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

        $columns = ['chest', 'length'];

        foreach (['sleeve', 'shoulder'] as $optional) {
            if ($rows->contains(fn (SizeGuideRow $row): bool => $row->{$optional} !== null)) {
                $columns[] = $optional;
            }
        }

        return [
            'unit' => 'cm',
            'types' => [
                $category->slug => [
                    'columns' => $columns,
                    'rows' => $rows->map(fn (SizeGuideRow $row): array => [
                        'size' => (string) $row->size_name,
                        'measurements' => array_map(
                            fn (?float $value): float => (float) $value,
                            array_intersect_key(
                                [
                                    'chest' => $row->chest,
                                    'length' => $row->length,
                                    'sleeve' => $row->sleeve,
                                    'shoulder' => $row->shoulder,
                                ],
                                array_flip($columns)
                            )
                        ),
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
            'is_active',
        ]));
    }

    /**
     * Synchronize the guide's measurement rows: existing rows not
     * present in the submitted list are removed and incoming ones
     * are inserted (or updated when an `id` is supplied).
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
            if (! isset($row['size_name'], $row['chest'], $row['length'])) {
                continue;
            }

            $payload = [
                'size_guide_id' => $guide->id,
                'size_name' => (string) $row['size_name'],
                'chest' => (float) $row['chest'],
                'length' => (float) $row['length'],
                'sleeve' => isset($row['sleeve']) && $row['sleeve'] !== ''
                    ? (float) $row['sleeve']
                    : null,
                'shoulder' => isset($row['shoulder']) && $row['shoulder'] !== ''
                    ? (float) $row['shoulder']
                    : null,
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
}
