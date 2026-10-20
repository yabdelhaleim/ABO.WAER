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
