<?php

namespace App\Services;

use App\Models\Governorate;
use App\Models\ShippingSetting;
use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ShippingService
{
    /**
     * List every shipping zone with its governorates, ordered for the admin tree.
     *
     * @return Collection<int, ShippingZone>
     */
    public function getZonesWithGovernorates(): Collection
    {
        return ShippingZone::with('governorates')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The global shipping settings as typed values.
     *
     * @return array{free_shipping_enabled: bool, free_shipping_threshold: float, cod_fee: float, default_fee: float, note_en: string, note_ar: string}
     */
    public function getGlobalSettings(): array
    {
        return [
            'free_shipping_enabled' => (bool) ShippingSetting::get('free_shipping_enabled', false),
            'free_shipping_threshold' => (float) ShippingSetting::get('free_shipping_threshold', 1000),
            'cod_fee' => (float) ShippingSetting::get('cod_fee', 15),
            'default_fee' => (float) ShippingSetting::get('default_fee', 50),
            'note_en' => (string) ShippingSetting::get('note_en', ''),
            'note_ar' => (string) ShippingSetting::get('note_ar', ''),
        ];
    }

    /**
     * Persist the global shipping settings in one transaction.
     *
     * @param  array<string, mixed>  $settings
     */
    public function updateGlobalSettings(array $settings): void
    {
        DB::transaction(function () use ($settings) {
            foreach ($settings as $key => $value) {
                ShippingSetting::set($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }
        });
    }

    /**
     * Create a zone, deriving a sort order when one isn't supplied.
     *
     * @param  array<string, mixed>  $data
     */
    public function createZone(array $data): ShippingZone
    {
        return DB::transaction(function () use ($data) {
            $data['sort_order'] = $data['sort_order'] ?? ((int) ShippingZone::max('sort_order') + 1);
            $data['is_active'] = $data['is_active'] ?? true;

            return ShippingZone::create($data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateZone(ShippingZone $zone, array $data): ShippingZone
    {
        $zone->update($data);

        return $zone->fresh(['governorates']);
    }

    /**
     * Delete a zone together with all of its governorates.
     */
    public function deleteZone(ShippingZone $zone): void
    {
        DB::transaction(function () use ($zone) {
            $zone->governorates()->delete();
            $zone->delete();
        });
    }

    /**
     * Create a governorate, deriving a sort order within its zone when
     * one isn't supplied.
     *
     * @param  array<string, mixed>  $data
     */
    public function createGovernorate(array $data): Governorate
    {
        return DB::transaction(function () use ($data) {
            $data['sort_order'] = $data['sort_order'] ?? ((int) Governorate::where('shipping_zone_id', $data['shipping_zone_id'])->max('sort_order') + 1);
            $data['is_active'] = $data['is_active'] ?? true;
            $data['allows_cod'] = $data['allows_cod'] ?? true;

            return Governorate::create($data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGovernorate(Governorate $governorate, array $data): Governorate
    {
        $governorate->update($data);

        return $governorate->fresh(['zone']);
    }

    /**
     * Delete a single governorate.
     */
    public function deleteGovernorate(Governorate $governorate): void
    {
        $governorate->delete();
    }
}
