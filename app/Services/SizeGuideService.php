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
     * The measuring instructions the storefront shows under a size
     * table. They are static — the database stores no such copy — and
     * they carry no numbers, so they can never disagree with the
     * measurements above them.
     *
     * @var list<array{label: string, hint: string}>
     */
    protected const HOW_TO_MEASURE = [
        ['label' => 'A · Chest', 'hint' => 'Measured flat, 1 cm below the armhole.'],
        ['label' => 'B · Length', 'hint' => 'From the highest point of the shoulder to the hem.'],
        ['label' => 'C · Shoulder', 'hint' => 'From one shoulder seam to the other, across the back.'],
        ['label' => 'D · Sleeve', 'hint' => 'From the shoulder seam to the end of the cuff.'],
    ];

    /**
     * The measurement columns the storefront table shows, in order.
     *
     * @var list<string>
     */
    protected const COLUMNS = ['chest', 'length', 'shoulder', 'sleeve'];

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
     * The published guide of a category, with its measurement rows.
     *
     * A guide belongs to a category (size_guides.category_id) and a
     * product belongs to a category too, so the guide a shopper needs is
     * the published one of the product's own category. `is_active` is
     * the switch the admin flips to publish a guide.
     */
    public function publishedForCategory(?Category $category): ?SizeGuide
    {
        if (! $category) {
            return null;
        }

        return SizeGuide::query()
            ->where('category_id', $category->id)
            ->where('is_active', true)
            ->with('rows')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The storefront chart payload for a guide.
     *
     * The shape is the one resources/views/components/size-guide/modal
     * .blade.php documents: the measurements are filed under the
     * category slug, so the modal looks the product's own category up
     * and never falls back to another category's numbers.
     *
     * @return array{unit: string, types: array<string, array{columns: list<string>, rows: list<array{size: string, measurements: array<string, float|null>}>}>, howToMeasure: list<array{label: string, hint: string}>}
     */
    public function chartFor(SizeGuide $guide, Category $category): array
    {
        return [
            'unit' => 'cm',
            'types' => [
                $category->slug => [
                    'columns' => self::COLUMNS,
                    'rows' => $guide->rows
                        ->map(fn (SizeGuideRow $row): array => [
                            'size' => $row->size_name,
                            'measurements' => [
                                'chest' => $row->chest,
                                'length' => $row->length,
                                'shoulder' => $row->shoulder,
                                'sleeve' => $row->sleeve,
                            ],
                        ])
                        ->all(),
                ],
            ],
            'howToMeasure' => self::HOW_TO_MEASURE,
        ];
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
