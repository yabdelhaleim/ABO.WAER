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
        $payload = $this->service->getZonesWithGovernorates()
            ->map(fn (ShippingZone $zone) => $this->zonePayload($zone))
            ->values()
            ->all();

        // The endpoints the screen talks to. The placeholders are
        // filled in by shipping.js with the row id.
        $routesJson = json_encode([
            'settings' => route('admin.shipping.settings.update'),
            'zoneStore' => route('admin.shipping.zones.store'),
            'zoneUpdate' => route('admin.shipping.zones.update', ['shippingZone' => '__ZONE__']),
            'zoneDestroy' => route('admin.shipping.zones.destroy', ['shippingZone' => '__ZONE__']),
            'zoneReorder' => route('admin.shipping.zones.reorder', ['shippingZone' => '__ZONE__']),
            'governorateStore' => route('admin.shipping.governorates.store'),
            'governorateUpdate' => route('admin.shipping.governorates.update', ['governorate' => '__GOVERNORATE__']),
            'governorateDestroy' => route('admin.shipping.governorates.destroy', ['governorate' => '__GOVERNORATE__']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return view('admin.shipping.index', [
            'zonesJson' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'settingsJson' => json_encode($this->service->getGlobalSettings(), JSON_UNESCAPED_UNICODE),
            'routesJson' => $routesJson,
        ]);
    }

    /**
     * The zone as the screen reads it.
     *
     * The screen matches rows by their string id, so the id the
     * database holds travels next to it as dbId.
     *
     * @return array<string, mixed>
     */
    protected function zonePayload(ShippingZone $zone): array
    {
        return [
            'id' => 'z-'.$zone->id,
            'dbId' => $zone->id,
            'name' => $zone->name,
            'nameAr' => $zone->name_ar ?? '',
            'fee' => (float) $zone->fee,
            'timeFrom' => (int) $zone->time_from,
            'timeTo' => (int) $zone->time_to,
            'active' => (bool) $zone->is_active,
            'governorates' => $zone->governorates
                ->map(fn (Governorate $governorate) => $this->governoratePayload($governorate))
                ->values()
                ->all(),
        ];
    }

    /**
     * The governorate as the screen reads it.
     *
     * @return array<string, mixed>
     */
    protected function governoratePayload(Governorate $governorate): array
    {
        return [
            'id' => 'g-'.$governorate->id,
            'dbId' => $governorate->id,
            'parentId' => 'z-'.$governorate->shipping_zone_id,
            'name' => $governorate->name,
            'nameAr' => $governorate->name_ar ?? '',
            'fee' => $governorate->fee !== null ? (float) $governorate->fee : null,
            'timeFrom' => $governorate->time_from !== null ? (int) $governorate->time_from : null,
            'timeTo' => $governorate->time_to !== null ? (int) $governorate->time_to : null,
            'cod' => (bool) $governorate->allows_cod,
            'active' => (bool) $governorate->is_active,
        ];
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

        return response()->json([
            'success' => true,
            'message' => 'Settings saved.',
        ]);
    }

    public function storeZone(ShippingZoneRequest $request): JsonResponse
    {
        $zone = $this->service->createZone($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Zone created.',
            'zone' => $this->zonePayload($zone->load('governorates')),
        ], 201);
    }

    public function updateZone(ShippingZoneRequest $request, ShippingZone $shippingZone): JsonResponse
    {
        $zone = $this->service->updateZone($shippingZone, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Zone saved.',
            'zone' => $this->zonePayload($zone),
        ]);
    }

    /**
     * Write the order of the governorates inside a zone.
     */
    public function reorderGovernorates(ShippingZone $shippingZone, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'governorates' => ['required', 'array', 'max:100'],
            'governorates.*' => ['integer', 'distinct'],
        ]);

        $this->service->reorderGovernorates($shippingZone, $validated['governorates']);

        return response()->json([
            'success' => true,
            'message' => 'Order updated.',
            'zone' => $this->zonePayload($shippingZone->fresh('governorates')),
        ]);
    }

    public function destroyZone(ShippingZone $shippingZone): JsonResponse
    {
        $this->service->deleteZone($shippingZone);

        return response()->json([
            'success' => true,
            'message' => 'Zone deleted.',
        ]);
    }

    public function storeGovernorate(GovernorateRequest $request): JsonResponse
    {
        $governorate = $this->service->createGovernorate($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Governorate created.',
            'governorate' => $this->governoratePayload($governorate),
        ], 201);
    }

    public function updateGovernorate(GovernorateRequest $request, Governorate $governorate): JsonResponse
    {
        $governorate = $this->service->updateGovernorate($governorate, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Governorate saved.',
            'governorate' => $this->governoratePayload($governorate),
        ]);
    }

    public function destroyGovernorate(Governorate $governorate): JsonResponse
    {
        $this->service->deleteGovernorate($governorate);

        return response()->json([
            'success' => true,
            'message' => 'Governorate deleted.',
        ]);
    }
}
