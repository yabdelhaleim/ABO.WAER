<?php

namespace App\Services\Admin;

use App\Models\Campaign;
use App\Models\Customer;
use App\Notifications\CampaignNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Hands one campaign message to one customer.
 *
 * SendCampaignJob owns the *delivery run* — who is in the segment, in what
 * order, how the counter moves — and delegates the act of messaging a single
 * customer here. Keeping the two apart means the queue, chunking and
 * status-transition logic never has to change when the transport does.
 *
 * Phase 7 · Step 4 wires the real Notification call in. The channel routing
 * (mail / database / sms-log) lives inside {@see CampaignNotification::via()}
 * so this service only has to hand the customer and the campaign to the
 * framework and let it pick the right transport.
 */
class CampaignDeliveryService
{
    /**
     * Deliver one campaign to one customer.
     *
     * Called once per customer per chunk by SendCampaignJob, so it must stay
     * cheap and must never assume a batch context.
     */
    public function deliverToCustomer(Campaign $campaign, Customer $customer): bool
    {
        try {
            Notification::send($customer, new CampaignNotification($campaign));

            return true;
        } catch (\Throwable $e) {
            // A failure on one customer must not abort the whole chunk — the
            // outer SendCampaignJob is the unit of retry, and even within
            // one chunk the counters should reflect the customer's that
            // actually went out.
            Log::channel('stack')->error('Campaign delivery failed for customer.', [
                'campaign_id' => $campaign->id,
                'customer_id' => $customer->id,
                'customer_email' => $customer->email,
                'campaign_type' => $campaign->type->value,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
