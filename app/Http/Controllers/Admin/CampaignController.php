<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CampaignAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCampaignRequest;
use App\Http\Requests\Admin\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Models\Coupon;
use App\Services\Admin\CampaignService;
use App\Services\CustomerSegmentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function __construct(
        protected CampaignService $campaignService,
        protected CustomerSegmentationService $segmentationService,
    ) {}

    /**
     * Render the admin campaigns index with the JSON payload injected
     * for public/admin/js/campaigns.js to hydrate on load.
     *
     * The live audience counts ride along in the same response so the
     * slide-over paints real reach numbers immediately instead of waiting
     * on its first admin.campaigns.audienceCount round trip.
     */
    public function index(): View
    {
        $stats = $this->campaignService->getDashboardStats();
        $campaigns = $this->campaignService->getAllCampaignsForAdmin();
        $campaignsJson = json_encode($campaigns, JSON_UNESCAPED_UNICODE);
        $audienceCounts = $this->getAudienceCounts();
        $coupons = Coupon::where('is_active', true)->orderBy('code')->get(['id', 'code', 'type', 'value']);

        return view('admin.campaigns.index', compact('stats', 'campaignsJson', 'audienceCounts', 'coupons'));
    }

    /**
     * Persist a new campaign and return the saved record in the admin payload shape.
     */
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $data = $request->validated();
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }

        $campaign = $this->campaignService->createCampaign($data);

        return response()->json([
            'success' => true,
            'message' => 'Campaign created successfully.',
            'campaign' => $campaign->toAdminPayload(),
        ], 201);
    }

    /**
     * Return a single campaign in the admin payload shape — used by the
     * slide-over when the admin clicks Edit.
     */
    public function show(Campaign $campaign): JsonResponse
    {
        return response()->json([
            'success' => true,
            'campaign' => $campaign->toAdminPayload(),
        ]);
    }

    /**
     * Update an existing campaign and return the refreshed payload.
     *
     * ValidationException from the service bubbles up as a 422 response
     * (ended campaigns are immutable).
     */
    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validated();
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }

        $updated = $this->campaignService->updateCampaign($campaign, $data);

        return response()->json([
            'success' => true,
            'message' => 'Campaign updated successfully.',
            'campaign' => $updated->toAdminPayload(),
        ]);
    }

    /**
     * Delete a campaign. Active campaigns surface a 422 from the service.
     */
    public function destroy(Campaign $campaign): JsonResponse
    {
        $this->campaignService->deleteCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campaign deleted successfully.',
        ]);
    }

    /**
     * Duplicate a campaign as a fresh draft and return the new payload.
     */
    public function duplicate(Campaign $campaign): JsonResponse
    {
        $duplicate = $this->campaignService->duplicateCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campaign duplicated as a new draft.',
            'campaign' => $duplicate->toAdminPayload(),
        ], 201);
    }

    /**
     * Hand the campaign to the background queue and return straight away.
     *
     * The actual delivery is SendCampaignJob's work, so the response only
     * confirms the handover — the counters in the returned payload will be
     * zero until the worker has walked the audience. ValidationException
     * from the service surfaces as a 422 (an ended campaign, or one already
     * in flight).
     */
    public function sendNow(Campaign $campaign): JsonResponse
    {
        $this->campaignService->dispatchCampaign($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Campaign dispatched successfully to background queue.',
            'campaign' => $campaign->fresh()->toAdminPayload(),
        ]);
    }

    /**
     * Size of one audience segment, for the reach figure shown beside the
     * audience picker and the Send button.
     *
     * The audience arrives as a resolved CampaignAudience — an unrecognised
     * segment 404s at the router before it reaches the controller.
     */
    public function previewAudienceCount(CampaignAudience $audience): JsonResponse
    {
        return response()->json([
            'success' => true,
            'audience' => $audience->value,
            'label' => $audience->label(),
            'count' => $this->segmentationService->getAudienceCount($audience),
        ]);
    }

    /**
     * Every audience's size, keyed by its stored value, for the index view.
     *
     * @return array<string, int>
     */
    protected function getAudienceCounts(): array
    {
        $counts = [];

        foreach (CampaignAudience::cases() as $audience) {
            $counts[$audience->value] = $this->segmentationService->getAudienceCount($audience);
        }

        return $counts;
    }
}
