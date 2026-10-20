<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Open- and click-tracking endpoints for marketing campaigns.
 *
 * The campaign email embeds a 1x1 GIF whose src is {@see trackOpen} and rewrites
 * its CTA href through {@see trackClick}. Both routes bump a counter on the
 * campaign row so the admin dashboard's KPIs (sent / opened / clicked) stay
 * trustworthy without having to parse raw mail logs.
 *
 * Routes are public and unauthenticated by design — the email client does not
 * carry the customer's session, and the link is opened from a desktop mail
 * reader, not the store's own checkout flow. Counter integrity is the only
 * concern; an inflated counter cannot redeem a coupon or expose order data.
 */
class CampaignTrackingController extends Controller
{
    /**
     * 1x1 transparent GIF payload used by the tracking pixel.
     *
     * The byte sequence is the canonical smallest possible GIF89a image —
     * 26 bytes. Cached in a static so each request does not reallocate it.
     */
    protected const string TRANSPARENT_GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * Increment opened_count and return the tracking pixel.
     *
     * The increment uses increment() (not a save() with full attribute writes)
     * so two simultaneous opens cannot lose each other to a lost-update race
     * the way a read-modify-write on a stale model would.
     */
    public function trackOpen(Campaign $campaign): Response
    {
        $campaign->increment('opened_count');

        return response(self::TRANSPARENT_GIF, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => (string) strlen(self::TRANSPARENT_GIF),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Increment clicked_count and redirect the customer to the real URL.
     *
     * The destination is read from the `url` query parameter the email
     * produced; without it we fall back to the shop landing so the open
     * click never lands on a broken page. We only honour http/https URLs so
     * a malicious open cannot be used to fish the recipient with javascript:
     * or other bespoke schemes.
     *
     * @return RedirectResponse
     */
    public function trackClick(Campaign $campaign, Request $request)
    {
        $campaign->increment('clicked_count');

        $target = (string) $request->query('url', route('shop'));
        $safe = $this->normalizeRedirectTarget($target);

        return redirect()->away($safe, 302);
    }

    /**
     * Allow only http(s) targets and resolve any non-string input to the
     * shop landing. Prevents javascript:, data:, file:// and similar from
     * being used as the click-through destination.
     */
    protected function normalizeRedirectTarget(mixed $target): string
    {
        if (! is_string($target) || $target === '') {
            return route('shop');
        }

        $scheme = parse_url($target, PHP_URL_SCHEME);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return route('shop');
        }

        return $target;
    }
}
