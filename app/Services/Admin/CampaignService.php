<?php

namespace App\Services\Admin;

use App\Enums\CampaignStatus;
use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin campaigns screen: reads, serialises and mutates campaigns.
 *
 * public/admin/js/campaigns.js walks the CAMPAIGNS array that this
 * service serialises on the index page. Every field it touches (id,
 * preview, type, audience, status, schedule, sent, opened, clicked)
 * is read without guards from Campaign::toAdminPayload() so a missing
 * sent_count renders as a literal — the payload must keep nulls where
 * the static fixture used them.
 *
 * Mutations (create / update / delete / duplicate) are wrapped in a
 * DB transaction so a half-completed campaign can never be persisted.
 */
class CampaignService
{
    /**
     * Build the four KPI numbers shown on the campaigns index header.
     *
     * - active_campaigns_count:   campaigns currently in the 'active' status.
     * - scheduled_campaigns_count: campaigns waiting for their scheduled_for
     *   to elapse — the scheduler promotes them a minute later.
     * - total_sent_count:         cumulative messages sent across every campaign.
     * - avg_open_rate:            mean open rate % over campaigns with sent_count > 0.
     * - avg_click_rate:           mean click-through rate % over campaigns with sent_count > 0.
     *
     * The averages are computed in PHP so a division-by-zero on a single
     * campaign can never tank the stat; the query filters that case out
     * before we sum.
     *
     * @return array{
     *     active_campaigns_count: int,
     *     scheduled_campaigns_count: int,
     *     total_sent_count: int,
     *     avg_open_rate: float,
     *     avg_click_rate: float
     * }
     */
    public function getDashboardStats(): array
    {
        $activeCampaignsCount = (int) Campaign::query()
            ->where('status', CampaignStatus::Active->value)
            ->count();

        // Campaigns waiting for their scheduled_for to elapse. The
        // scheduler promotes them one minute later, so this is the
        // number the "Scheduled" KPI card shows — a campaign whose
        // moment has not come yet, not one that has already been sent.
        $scheduledCampaignsCount = (int) Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->whereNotNull('scheduled_for')
            ->count();

        $totalSentCount = (int) (Campaign::query()->sum('sent_count') ?? 0);

        $sentCampaigns = Campaign::query()
            ->where('sent_count', '>', 0)
            ->get(['sent_count', 'opened_count', 'clicked_count']);

        $avgOpenRate = 0.0;
        $avgClickRate = 0.0;

        if ($sentCampaigns->isNotEmpty()) {
            $openSum = 0.0;
            $clickSum = 0.0;

            foreach ($sentCampaigns as $campaign) {
                $openSum += ($campaign->opened_count / $campaign->sent_count) * 100;
                $clickSum += ($campaign->clicked_count / $campaign->sent_count) * 100;
            }

            $count = $sentCampaigns->count();
            $avgOpenRate = round($openSum / $count, 1);
            $avgClickRate = round($clickSum / $count, 1);
        }

        return [
            'active_campaigns_count' => $activeCampaignsCount,
            'scheduled_campaigns_count' => $scheduledCampaignsCount,
            'total_sent_count' => $totalSentCount,
            'avg_open_rate' => $avgOpenRate,
            'avg_click_rate' => $avgClickRate,
        ];
    }

    /**
     * Every campaign, newest first, transformed into the
     * admin payload shape that campaigns.js walks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllCampaignsForAdmin(): array
    {
        return Campaign::query()
            ->latest('id')
            ->get()
            ->map(fn (Campaign $campaign): array => $campaign->toAdminPayload())
            ->all();
    }

    /**
     * Persist a new campaign. The status is auto-resolved:
     *   - 'scheduled' when scheduled_for sits in the future
     *   - 'draft'     otherwise (the safe default for the admin form)
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createCampaign(array $data): Campaign
    {
        return DB::transaction(function () use ($data): Campaign {
            $attributes = $this->extractAttributes($data);
            $attributes['status'] = $this->resolveInitialStatus($data);

            return Campaign::create($attributes);
        });
    }

    /**
     * Update an existing campaign. Ended campaigns are immutable —
     * they belong to the historical record and are written off
     * for reporting.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updateCampaign(Campaign $campaign, array $data): Campaign
    {
        if ($campaign->status === CampaignStatus::Ended) {
            throw ValidationException::withMessages([
                'campaign' => 'Ended campaigns cannot be edited.',
            ]);
        }

        return DB::transaction(function () use ($campaign, $data): Campaign {
            $campaign->update($this->extractAttributes($data));

            return $campaign->fresh();
        });
    }

    /**
     * Remove a campaign. Active campaigns still being delivered are
     * protected — pausing and waiting for cron to flip them to 'ended'
     * is the recommended path. Drafts, scheduled and ended campaigns
     * can always be deleted.
     *
     * @throws ValidationException
     */
    public function deleteCampaign(Campaign $campaign): bool
    {
        if ($campaign->status === CampaignStatus::Active) {
            throw ValidationException::withMessages([
                'campaign' => 'Active campaigns cannot be deleted. Pause the campaign first and wait for it to end.',
            ]);
        }

        return (bool) $campaign->delete();
    }

    /**
     * Clone a campaign as a fresh draft. The clone always starts at
     * status=draft with zeroed interaction counters and a "(copy)"
     * suffix on the title so the admin can recognise it in the table.
     */
    public function duplicateCampaign(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign): Campaign {
            $clone = $campaign->replicate(['sent_count', 'opened_count', 'clicked_count', 'sent_at', 'scheduled_for']);
            $clone->title = $campaign->title.' (copy)';
            $clone->status = CampaignStatus::Draft;
            $clone->scheduled_for = null;
            $clone->sent_at = null;
            $clone->sent_count = 0;
            $clone->opened_count = 0;
            $clone->clicked_count = 0;
            $clone->save();

            return $clone->fresh();
        });
    }

    /**
     * Hand a campaign to the background queue for delivery.
     *
     * The status is flipped to 'active' here, synchronously, rather than
     * inside the job: the admin's "send now" must return with the campaign
     * already visibly in flight, and it is that status which blocks a second
     * click from stacking a duplicate run on top of the first. It also keeps
     * the campaign undeletable (see deleteCampaign) for the whole run.
     *
     * The row is re-read under a lock so two concurrent send-now clicks
     * cannot both pass the guard, and the job is dispatched only after the
     * transaction commits — otherwise a worker could pick it up and read a
     * status the database has not committed yet.
     *
     * @throws ValidationException
     */
    public function dispatchCampaign(Campaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if ($locked->status === CampaignStatus::Ended) {
                throw ValidationException::withMessages([
                    'campaign' => 'Ended campaigns cannot be sent.',
                ]);
            }

            if ($locked->status === CampaignStatus::Active) {
                throw ValidationException::withMessages([
                    'campaign' => 'This campaign is already being sent.',
                ]);
            }

            $locked->update(['status' => CampaignStatus::Active]);
        });

        SendCampaignJob::dispatch($campaign->fresh());
    }

    /**
     * Promote every campaign whose scheduled_for has elapsed into the queue.
     *
     * Called from routes/console.php on the every-minute scheduler. The call
     * is wrapped in queued dispatching rather than synchronous delivery so a
     * long audience segment never holds the scheduler tick past its minute;
     * each due campaign takes the same locked-transition path used by the
     * admin's Send-now button, which guarantees that a campaign can never be
     * picked up twice (the second promotion hits the "already active" guard).
     *
     * Drafts are intentionally not promoted — they have no scheduled_for and
     * the admin has not finished preparing them, so the scheduler must not
     * ship them by accident.
     *
     * @return int Number of campaigns that were handed to the queue.
     */
    public function dispatchDueScheduledCampaigns(): int
    {
        $due = Campaign::query()->scheduledForSending()->get();

        $dispatched = 0;

        foreach ($due as $campaign) {
            try {
                $this->dispatchCampaign($campaign);
                $dispatched++;
            } catch (ValidationException) {
                // Already running or ended between read and lock — skip it.
                // The scheduler will pick it up next tick if it is still eligible.
                continue;
            }
        }

        return $dispatched;
    }

    /**
     * Pick the fillable subset out of a request payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'title',
            'subject',
            'preview',
            'message',
            'type',
            'audience',
            'coupon',
            'deeplink',
            'scheduled_for',
        ]));
    }

    /**
     * Decide the initial status. A future scheduled_for promotes the
     * draft to 'scheduled' automatically; absent or past timestamps
     * keep the row as 'draft' so the admin can review before sending.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveInitialStatus(array $data): CampaignStatus
    {
        $scheduledFor = $data['scheduled_for'] ?? null;

        if ($scheduledFor && strtotime((string) $scheduledFor) > time()) {
            return CampaignStatus::Scheduled;
        }

        return CampaignStatus::Draft;
    }
}
