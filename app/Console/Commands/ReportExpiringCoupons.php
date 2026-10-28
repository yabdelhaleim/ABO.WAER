<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\StoreSetting;
use App\Notifications\CouponExpiringAdminNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Tell the admins when a coupon's window is closing.
 *
 * The bell's switch said the store would be told "when a coupon is
 * about to expire or did expire" — and nothing told it. A coupon that
 * ran out quietly just stopped working while the campaign that used it
 * was still running.
 *
 * This command keeps that promise. It looks for the coupons whose
 * window closes within the next day (and the ones that closed in the
 * last day) and rings the bell for each.
 *
 * A coupon is reported once: the notification itself is the record, so
 * a coupon that was already announced is not announced again on the
 * next run. The command is scheduled (routes/console.php) and can be
 * run by hand.
 */
#[Signature('coupons:report-expiring {--days=1 : How many days ahead to look}')]
#[Description('Tell the admins about the coupons that are about to expire, or have just expired')]
class ReportExpiringCoupons extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! StoreSetting::get('notifications.couponExpiring', false)) {
            $this->info('The coupon-expiry bell is off — nothing was reported.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));

        $now = now();
        $windowEnd = $now->copy()->addDays($days);

        // The coupons whose window closes inside the window, and the ones
        // that closed inside it — a coupon that has just expired is
        // still news.
        $candidates = Coupon::query()
            ->where('is_active', true)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now->copy()->subDay(), $windowEnd])
            ->orderBy('expires_at')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No coupon expires in the next '.$days.' day(s).');

            return self::SUCCESS;
        }

        $admins = Admin::query()->where('is_active', true)->get();

        if ($admins->isEmpty()) {
            $this->info('No active admin to tell.');

            return self::SUCCESS;
        }

        $reported = 0;

        foreach ($candidates as $coupon) {
            $expired = $coupon->expires_at?->isPast() ?? false;

            // The notification is the record that this coupon was
            // announced, so a coupon is not announced twice.
            if ($this->alreadyReported($coupon)) {
                continue;
            }

            Notification::send($admins, new CouponExpiringAdminNotification($coupon, $expired));

            $reported++;
        }

        $this->info($reported.' coupon(s) reported.');

        return self::SUCCESS;
    }

    /**
     * Whether the bell has already rung for this coupon.
     */
    protected function alreadyReported(Coupon $coupon): bool
    {
        return DatabaseNotification::query()
            ->where('type', CouponExpiringAdminNotification::class)
            ->where('data->id', 'coupon-'.$coupon->id)
            ->exists();
    }
}
