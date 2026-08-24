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
// Ten minutes behind the sweep (owner, 2026-08-24): the hour's newly
// payable sales are on the clock by then, and a merchant holding wallet
// balance has it spent on their oldest validated cashback — one batch per
// merchant per run, as far as the balance reaches. The reinstatement sweep
// at :00/:30 then sees the debt that this run cleared.
Schedule::command('manfaa:auto-settle-wallets')->hourlyAt(10)->withoutOverlapping()->onOneServer();
Schedule::command('manfaa:reinstate')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('manfaa:escalate')->dailyAt('09:00')->timezone(config('app.business_timezone'))->withoutOverlapping()->onOneServer();
Schedule::command('manfaa:remind-settlements')->dailyAt('09:00')->timezone(config('app.business_timezone'))->withoutOverlapping()->onOneServer();
Schedule::command('manfaa:suspend-overdue')->dailyAt('00:15')->timezone(config('app.business_timezone'))->withoutOverlapping()->onOneServer();
Schedule::command('manfaa:write-off')->dailyAt('01:00')->timezone(config('app.business_timezone'))->withoutOverlapping()->onOneServer();
Schedule::command('manfaa:reconcile')->dailyAt('02:00')->timezone(config('app.business_timezone'))->withoutOverlapping()->onOneServer();

// Expired mobile/vendor tokens authenticate nothing, but the rows persist
// forever without this. MobileTokenService reaps a user's own dead rows when
// they next sign in — which is exactly when a user who has STOPPED signing in
// never does. --hours=24 keeps a day's grace so a support conversation about
// "it signed me out yesterday" still has a row to look at.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping()->onOneServer();

// Idempotency keys are released on failure but kept forever on success, and
// M5 pointed every till sale at that table. Thirty days is long past any
// honest retry — a till draining a queue after days without signal still
// finds its keys — and well short of the unbounded window that turns a
// factory-reset device's restarted key counter into permanently refused
// sales. See PruneIdempotencyKeysCommand.
Schedule::command('manfaa:prune-idempotency-keys')->dailyAt('03:40')->withoutOverlapping()->onOneServer();

// OTP codes became the customer app's PRIMARY auth write table with R1's
// passwordless sign-in, and OtpService never deletes a row. Seven days is
// long past the 10-minute code and 15-minute signup-token windows. Same
// unbounded-table pattern the M5 review fixed for idempotency_keys.
Schedule::command('manfaa:prune-otp-codes')->dailyAt('03:50')->withoutOverlapping()->onOneServer();

// The POS-waiver month closes on the 3rd (owner, 2026-08-23): refunds from
// the month's last days have landed, and IsleBooks bills after that.
Schedule::command('manfaa:evaluate-pos-waivers')->monthlyOn(3, '06:00')->timezone(config('app.business_timezone'))->withoutOverlapping()->onOneServer();

// The referral safety net (owner, 2026-08-23). The transition hook pays the
// moment a friend's validated spend crosses the bar; this sweep catches what
// the hook cannot see — an admin lowering the threshold, or a hook a crash
// swallowed. Idempotent: once per referred customer, ever.
Schedule::command('manfaa:award-referral-bonuses')->dailyAt('06:30')->timezone(config('app.business_timezone'))->withoutOverlapping()->onOneServer();
