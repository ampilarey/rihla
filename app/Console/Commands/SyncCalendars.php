<?php

namespace App\Console\Commands;

use App\Models\CalendarFeed;
use App\Services\Stays\CalendarImport;
use Illuminate\Console\Command;

/**
 * Read every imported calendar — §16 Phase 16. Hourly: another site's
 * booking is closed here within the hour. One feed failing does not stop
 * the others; each records its own reason.
 */
class SyncCalendars extends Command
{
    protected $signature = 'stays:calendars';

    protected $description = 'Import every host\'s other-site calendars as blocked nights';

    public function handle(CalendarImport $import): int
    {
        $ok = 0;
        $failed = 0;

        CalendarFeed::query()->with('roomType')->each(function (CalendarFeed $feed) use ($import, &$ok, &$failed): void {
            $import->sync($feed) === null ? $failed++ : $ok++;
        });

        $this->info("Synced {$ok} calendar(s); {$failed} could not be read.");

        return self::SUCCESS;
    }
}
