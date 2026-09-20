<?php

namespace App\Console\Commands;

use App\Models\Notice;
use App\Services\Notices\Sweep;
use Illuminate\Console\Command;

/**
 * Raise what is outstanding — a cron line, not a queue worker.
 *
 * ADR 0002 rules out a queue on this host, so an event-driven fan-out
 * would run inside a customer's web request and fail silently. This is
 * idempotent: running it hourly and running it once a day produce the same
 * portal, because a second outstanding notice of the same kind is refused.
 */
class SweepNotices extends Command
{
    protected $signature = 'notices:sweep';

    protected $description = 'Raise notices for anything outstanding on live bookings';

    public function handle(Sweep $sweep): int
    {
        $raised = array_filter($sweep->run());

        if ($raised === []) {
            $this->info('Nothing new to tell anybody.');

            return self::SUCCESS;
        }

        foreach ($raised as $kind => $count) {
            $this->line(sprintf('%s: %d', (new Notice(['kind' => $kind]))->kindLabel(), $count));
        }

        return self::SUCCESS;
    }
}
