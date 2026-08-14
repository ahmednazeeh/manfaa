<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The clock (§7)
|--------------------------------------------------------------------------
|
| Storage stays UTC; jobs whose semantics are business-day ones (the
| escalation ladder, day-16 suspension, write-off, reconciliation) run
| pinned to the business timezone. The two sweeps that only compare stored
| instants run on a plain cadence — reinstatement every 30 minutes so
| settlement allocation needs no coupling to it.
|
| Every job takes both overlap guards: withoutOverlapping() so a slow run is
| never doubled on the same box, onOneServer() so a multi-server deploy fires
| each job once. Both mutexes live in the cache store — CACHE_STORE=redis in
| production, which supports the atomic locks onOneServer() requires.
|
*/

Schedule::command('manfaa:sweep-validation')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('manfaa:reinstate')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('manfaa:escalate')->dailyAt('09:00')->timezone(config('app.business_timezone'))->withoutOverlapping()->onOneServer();
Schedule::command('manfaa:suspend-overdue')->dailyAt('00:15')->timezone(config('app.business_timezone'))->withoutOverlapping()->onOneServer();
Schedule::command('manfaa:write-off')->dailyAt('01:00')->timezone(config('app.business_timezone'))->withoutOverlapping()->onOneServer();
Schedule::command('manfaa:reconcile')->dailyAt('02:00')->timezone(config('app.business_timezone'))->withoutOverlapping()->onOneServer();
