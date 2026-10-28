<?php

namespace App\Services\Admin;

use App\Models\CancellationReason;
use App\Models\Order;
use App\Models\StoreSetting;
use App\Services\ImageService;
use Illuminate\Support\Facades\DB;

/**
 * The settings the admin screen edits.
 *
 * The screen used to carry its values in a Blade array and keep them in
 * the browser: every field was a picture of a setting that did not
 * exist, the SAVE button showed a "Saved (demo)" toast, and the values
 * were thrown away with the page. This service is where they live now.
 *
 * Each setting is one row in the `store_settings` table, keyed by its
 * path (`store.name`, `orders.prefix`, `receipts.fields.address`, …)
 * and stored as JSON so a boolean comes back a boolean and a nested
 * block comes back a block. The defaults below are what a store that
 * has never touched the screen starts with — they are deliberately
 * empty where the old screen used to invent an answer: there is no
 * made-up phone number, no made-up address and no made-up return
 * policy, because a store that has not typed them does not have them.
 *
 * The cancellation reasons are not settings: they are rows of their own
 * table, shared with the orders that point at them, and they are read
 * and written here as such.
 */
class SettingsService
{
    /**
     * The settings a store starts with, grouped by the section of the
     * screen they belong to.
     *
     * @var array<string, array<string, mixed>>
     */
    public const DEFAULTS = [
        'store' => [
            'name' => 'ABO',
            'phone' => '',
            'whatsapp' => '',
            'email' => '',
            'address' => '',
            'logo' => '',
            'favicon' => '',
            'social' => [
                'instagram' => '',
                'facebook' => '',
                'tiktok' => '',
            ],
            'maintenance' => false,
        ],
        'orders' => [
            'minimum' => 0,
            'autoCancelHours' => 24,
            'guestCheckout' => true,
            'prefix' => 'ABO-',
        ],
        'inventory' => [
            'lowStockThreshold' => 5,
            'hideOutOfStock' => false,
        ],
        'receipts' => [
            'showLogo' => true,
            'headerText' => 'ABO Store',
            'footerNote' => '',
            'fields' => [
                'orderNumber' => true,
                'date' => true,
                'customerPhone' => true,
                'address' => false,
                'paymentMethod' => true,
                'shippingFee' => true,
            ],
            'paper' => 'a4',
        ],
        'notifications' => [
            'newOrder' => true,
            'lowStock' => true,
            'returnRequest' => true,
            'shipmentFailed' => false,
            'couponExpiring' => false,
            'browserSound' => true,
        ],
    ];

    /**
     * The whole screen as the browser reads it.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $store = $this->section('store');

        // The logo and the favicon are stored as paths and travel as
        // the URLs that serve them.
        $store['logo'] = ImageService::publicUrl($store['logo'] ?? null) ?? '';
        $store['favicon'] = ImageService::publicUrl($store['favicon'] ?? null) ?? '';

        return [
            'store' => $store,
            // The next order number is not a setting: it is what the
            // shop would hand out right now, so it is measured.
            'orders' => array_merge($this->section('orders'), [
                'nextNumber' => Order::nextOrderNumber(),
            ]),
            'inventory' => $this->section('inventory'),
            'reasons' => $this->reasons(),
            'receipts' => $this->section('receipts'),
            'notifications' => $this->section('notifications'),
        ];
    }

    /**
     * Store the settings the screen sent and hand back what is now
     * true — the browser replaces its copy with this answer and never
     * with what it hoped to save.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function update(array $validated): array
    {
        // The logo and the favicon belong to the upload that stores
        // them — the form only carries the URLs they are served from.
        if (isset($validated['store']) && is_array($validated['store'])) {
            unset($validated['store']['logo'], $validated['store']['favicon']);
        }

        DB::transaction(function () use ($validated): void {
            foreach (array_keys(self::DEFAULTS) as $section) {
                if (! array_key_exists($section, $validated)) {
                    continue;
                }

                foreach ($this->flatten($validated[$section], $section) as $path => $value) {
                    StoreSetting::set($path, $value);
                }
            }

            // The reasons are rows, not settings, and are only touched
            // when the screen actually sent them.
            if (array_key_exists('reasons', $validated)) {
                $this->syncReasons($validated['reasons']);
            }
        });

        return $this->payload();
    }

    /**
     * Put every setting back to its default.
     *
     * The cancellation reasons go with them: a store that restores its
     * defaults has no reason list, and the rows that are still named by
     * an order keep their label through the order's own history.
     *
     * @return array<string, mixed>
     */
    public function reset(): array
    {
        DB::transaction(function (): void {
            foreach (array_keys(self::DEFAULTS) as $section) {
                StoreSetting::query()->where('key', 'like', $section.'.%')->delete();
            }

            CancellationReason::query()->delete();
        });

        StoreSetting::flush();

        return $this->payload();
    }

    /**
     * One section of the screen: the stored values over the defaults.
     *
     * @return array<string, mixed>
     */
    public function section(string $section): array
    {
        $defaults = self::DEFAULTS[$section] ?? [];

        $values = [];
        foreach ($defaults as $key => $default) {
            $values[$key] = $this->value($section.'.'.$key, $default);
        }

        return $values;
    }

    /**
     * A single setting: the stored value when there is one, the default
     * otherwise. A value the admin cleared stays cleared.
     */
    protected function value(string $key, mixed $default): mixed
    {
        if (! array_key_exists($key, StoreSetting::allSettings())) {
            return $default;
        }

        $stored = StoreSetting::get($key);

        if ($stored === null) {
            return null;
        }

        if (is_array($default) && is_array($stored)) {
            return array_replace_recursive($default, $stored);
        }

        return $stored;
    }

    /**
     * The cancellation reasons as the screen reads them.
     *
     * @return array<int, array{id: int, en: string, ar: string, active: bool}>
     */
    public function reasons(): array
    {
        return CancellationReason::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (CancellationReason $reason): array => [
                'id' => (int) $reason->id,
                'en' => (string) $reason->reason,
                'ar' => (string) ($reason->reason_ar ?? ''),
                'active' => (bool) $reason->is_active,
            ])
            ->all();
    }

    /**
     * Write the reason list the screen sent.
     *
     * A row that is already in the table is updated in place, a row
     * without an id is a new reason, and a row that is in the table but
     * no longer in the list was removed by the admin and goes away with
     * it. The order of the list is the order of the rows.
     *
     * @param  array<int, array<string, mixed>>  $reasons
     */
    protected function syncReasons(array $reasons): void
    {
        $kept = [];

        foreach (array_values($reasons) as $index => $reason) {
            $en = trim((string) ($reason['en'] ?? ''));
            $ar = trim((string) ($reason['ar'] ?? ''));

            // A row with no wording in either language is not a reason.
            if ($en === '' && $ar === '') {
                continue;
            }

            $attributes = [
                'reason' => $en !== '' ? $en : $ar,
                'reason_ar' => $ar !== '' ? $ar : null,
                'is_active' => (bool) ($reason['active'] ?? true),
                'sort_order' => $index,
            ];

            $id = $reason['id'] ?? null;

            if (is_numeric($id) && CancellationReason::query()->whereKey((int) $id)->exists()) {
                CancellationReason::query()->whereKey((int) $id)->update($attributes);
                $kept[] = (int) $id;

                continue;
            }

            $kept[] = (int) CancellationReason::query()->create($attributes)->id;
        }

        CancellationReason::query()->whereNotIn('id', $kept)->delete();
    }

    /**
     * Flatten a section into the paths its settings are stored under.
     *
     * `['store' => ['social' => ['instagram' => 'x']]]` becomes
     * `['store.social.instagram' => 'x']`.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function flatten(array $values, string $prefix): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
