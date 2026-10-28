<?php

use App\Services\Admin\CampaignService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Marketing Campaign Scheduler (Phase 7 · Step 4)
|--------------------------------------------------------------------------
|
| Every minute, ask App\Services\Admin\CampaignService::dispatchDueScheduledCampaigns()
| to walk every campaign whose scheduled_for has elapsed and queue it
| for delivery. The service uses Campaign::scopeScheduledForSending() to
| filter the candidates, and a guard inside dispatchCampaign() to skip
| campaigns that have already moved to active or ended between the read
| and the row lock.
|
| `withoutOverlapping()` ensures a slow campaign cannot stack a second
| tick on top of itself if the previous tick is still walking the queue.
| The name ('dispatch-scheduled-campaigns') gives the schedule a stable
| identifier for `php artisan schedule:list` and the scheduler log.
*/
Schedule::call(function () {
    app(CampaignService::class)->dispatchDueScheduledCampaigns();
})
    ->everyMinute()
    ->name('dispatch-scheduled-campaigns')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Unpaid orders scheduler
|--------------------------------------------------------------------------
|
| Every quarter of an hour, cancel the orders nobody paid for. The
| window is the store's own ("Auto-cancel unpaid orders" on the
| settings screen) — the command reads it and cancels the orders that
| have been sitting unpaid for longer than that.
|
| The command hands every order it cancels to the same cancel flow the
| admin's button uses, so the stock goes back and the status history
| records that the system did it.
|
| `withoutOverlapping()` keeps a slow run from stacking a second tick
| on top of itself.
*/
Schedule::command('orders:cancel-unpaid')
    ->everyFifteenMinutes()
    ->name('cancel-unpaid-orders')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Coupon expiry scheduler
|--------------------------------------------------------------------------
|
| Every hour, tell the admins about the coupons whose window is
| closing. The bell's "Coupon expiring" switch decides whether this
| runs at all; the command reads it and stays quiet when it is off.
|
| A coupon is announced once — the notification itself is the record —
| so an hourly run does not repeat the same warning every hour.
*/
Schedule::command('coupons:report-expiring')
    ->hourly()
    ->name('report-expiring-coupons')
    ->withoutOverlapping();
