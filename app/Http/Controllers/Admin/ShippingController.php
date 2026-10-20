<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GovernorateRequest;
use App\Http\Requests\Admin\ShippingZoneRequest;
use App\Models\Governorate;
use App\Models\ShippingZone;
use App\Services\ShippingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShippingController extends Controller
{
    public function __construct(protected ShippingService $service) {}

    /**
     * Show the admin shipping page with the zone tree and global settings
     * serialized for public/admin/js/shipping.js.
     */
    public function index(): View
    {
        $zones = $this->service->getZonesWithGovernorates();

        $payload = $zones->map(fn (ShippingZone $zone) => [
            'id' => 'z-'.$zone->id,
            'dbId' => $zone->id,
            'name' => $zone->name,
            'nameAr' => $zone->name_ar ?? '',
            'fee' => (float) $zone->fee,
            'timeFrom' => (int) $zone->time_from,
            'timeTo' => (int) $zone->time_to,
            'active' => (bool) $zone->is_active,
            'governorates' => $zone->governorates->map(fn (Governorate $governorate) => [
                'id' => 'g-'.$governorate->id,
                'dbId' => $governorate->id,
                'parentId' => 'z-'.$zone->id,
                'name' => $governorate->name,
                'nameAr' => $governorate->name_ar ?? '',
                'fee' => $governorate->fee !== null ? (float) $governorate->fee : null,
                'timeFrom' => $governorate->time_from !== null ? (int) $governorate->time_from : null,
                'timeTo' => $governorate->time_to !== null ? (int) $governorate->time_to : null,
                'cod' => (bool) $governorate->allows_cod,
                'active' => (bool) $governorate->is_active,
            ])->values()->all(),
        ])->values()->all();

        return view('admin.shipping.index', [
            'zonesJson' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'settingsJson' => json_encode($this->service->getGlobalSettings(), JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Update the global shipping settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'free_shipping_enabled' => ['sometimes', 'boolean'],
            'free_shipping_threshold' => ['nullable', 'numeric', 'min:0'],
            'cod_fee' => ['nullable', 'numeric', 'min:0'],
            'default_fee' => ['nullable', 'numeric', 'min:0'],
            'note_en' => ['nullable', 'string', 'max:500'],
            'note_ar' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->updateGlobalSettings($validated);

        return response()->json(['success' => true, 'message' => 'Settings updated successfully.']);
    }

    public function storeZone(ShippingZoneRequest $request): JsonResponse
    {
        $zone = $this->service->createZone($request->validated());

        return response()->json(['success' => true, 'zone' => $zone], 201);
    }

    public function updateZone(ShippingZoneRequest $request, ShippingZone $shippingZone): JsonResponse
    {
        $zone = $this->service->updateZone($shippingZone, $request->validated());

        return response()->json(['success' => true, 'zone' => $zone]);
    }

    public function destroyZone(ShippingZone $shippingZone): JsonResponse
    {
        $this->service->deleteZone($shippingZone);

        return response()->json(['success' => true]);
    }

    public function storeGovernorate(GovernorateRequest $request): JsonResponse
    {
        $governorate = $this->service->createGovernorate($request->validated());

        return response()->json(['success' => true, 'governorate' => $governorate], 201);
    }

    public function updateGovernorate(GovernorateRequest $request, Governorate $governorate): JsonResponse
    {
        $governorate = $this->service->updateGovernorate($governorate, $request->validated());

        return response()->json(['success' => true, 'governorate' => $governorate]);
    }

    public function destroyGovernorate(Governorate $governorate): JsonResponse
    {
        $this->service->deleteGovernorate($governorate);

        return response()->json(['success' => true]);
    }
}
