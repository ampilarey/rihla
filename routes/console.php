<?php

use App\Console\Commands\Preflight;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The schedule — §16.12
|--------------------------------------------------------------------------
|
| These commands existed and nothing ran them. A hold whose deposit never
| came kept its room off the calendar until the next request for that
| room happened to reclaim it, so a guesthouse nobody asked about for a
| week read as full. The server's cron runs `php artisan schedule:run`
| once a minute (scripts/install-scheduler-cron.sh puts it there), and
| this is what that line does.
|
| Each command is idempotent and safe to run late — ADR 0002: no queue
| worker, cron's floor is one minute. The overlap lock expires after ten
| minutes, so a run killed by the host cannot hold the next one off for
| the default day.
|
*/

Schedule::command('stays:expire-holds')->everyTenMinutes()->withoutOverlapping(10);

Schedule::command('bookings:expire-holds')->everyTenMinutes()->withoutOverlapping(10);

Schedule::command('notices:sweep')->hourly()->withoutOverlapping(10);

// §16.9: last month's host statements, on the 2nd so the month has closed.
Schedule::command('stays:statements')->monthlyOn(2, '03:15')->withoutOverlapping(30);

// Other sites' calendars — §16 Phase 16. Hourly, so a room booked on
// another site is closed here within the hour.
Schedule::command('stays:calendars')->hourlyAt(20)->withoutOverlapping(30);

// Proof the scheduler is alive, read by `rihla:preflight`. A missing cron
// line fails nothing loudly — holds just stop expiring — so the deploy
// checklist is where somebody finds out.
Schedule::call(fn () => Cache::forever(Preflight::SCHEDULE_HEARTBEAT, now()->getTimestamp()))
    ->everyMinute()
    ->name('schedule-heartbeat');
