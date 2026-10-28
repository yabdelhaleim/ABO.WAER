<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsRequest;
use App\Models\ShippingSetting;
use App\Models\StoreSetting;
use App\Services\Admin\AdminActivityService;
use App\Services\Admin\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The admin settings screen.
 *
 * The page is served with the settings as they are stored, and every
 * change goes back through this controller: the screen holds no truth
 * of its own, it draws what the server sent and asks the server to
 * write what the operator changed.
 */
class SettingsController extends Controller
{
    public function __construct(
        protected SettingsService $settings,
        protected AdminActivityService $activity,
    ) {}

    /**
     * Show the settings screen with everything it draws.
     */
    public function index(): View
    {
        $payload = $this->settings->payload();

        return view('admin.settings.index', [
            'payload' => $payload,
            'receiptPreview' => $this->receiptPreview($payload),
            'routes' => [
                'save' => route('admin.settings.update'),
                'branding' => route('admin.settings.branding'),
                'reset' => route('admin.settings.reset'),
            ],
        ]);
    }

    /**
     * What the receipt preview shows.
     *
     * The preview is a picture of a receipt nobody has printed yet: the
     * order number is the one the shop would hand out next, the date is
     * today, and the two lines that belong to a customer are empty —
     * there is no customer to know yet.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function receiptPreview(array $payload): array
    {
        return [
            'orderNumber' => (string) $payload['orders']['nextNumber'],
            'date' => now()->format('j M Y'),
            'customerPhone' => '—',
            'address' => '—',
            'paymentMethod' => 'Cash on delivery',
            'shippingFee' => number_format((float) ShippingSetting::get('default_fee', 50), 2).' EGP',
        ];
    }

    /**
     * Store the settings the screen sent.
     *
     * The answer carries the settings as they now are — including the
     * cancellation reasons, which come back with the ids the database
     * gave them — so the browser can put the server's truth on screen
     * instead of what it hoped to save.
     */
    public function update(SettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $payload = $this->settings->update($validated);

        $this->activity->record('settings.updated', null, [
            'sections' => array_keys($validated),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Settings saved.',
            'settings' => $payload,
        ]);
    }

    /**
     * Put every setting back to its default.
     */
    public function reset(): JsonResponse
    {
        $payload = $this->settings->reset();

        $this->activity->record('settings.reset');

        return response()->json([
            'success' => true,
            'message' => 'Settings restored to their defaults.',
            'settings' => $payload,
        ]);
    }

    /**
     * Store the logo or the favicon the operator picked.
     *
     * The file goes to the public disk the way a banner image does, and
     * the path that serves it becomes the setting. The answer carries
     * the settings as they now are, so the screen can show the logo it
     * has just stored.
     */
    public function branding(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logo' => ['nullable', 'file', 'image', 'max:2048'],
            'favicon' => ['nullable', 'file', 'image', 'max:1024'],
        ]);

        foreach (array_keys($validated) as $key) {
            if (! $request->hasFile($key)) {
                continue;
            }

            // The file goes to the public disk the way a banner image
            // does, and the path that serves it becomes the setting.
            $path = $request->file($key)->store('settings', 'public');

            StoreSetting::set('store.'.$key, $path);
        }

        $payload = $this->settings->payload();

        $this->activity->record('settings.branding', null, [
            'files' => array_keys($validated),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Branding saved.',
            'settings' => $payload,
        ]);
    }
}
