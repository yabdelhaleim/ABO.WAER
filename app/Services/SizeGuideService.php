<?php

namespace App\Services;

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
