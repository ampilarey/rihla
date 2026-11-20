<?php

namespace App\Console\Commands;

use App\Models\Notice;
use App\Services\Notices\Sweep;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Modules\Umrah\Models\Booking;

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

    public function handle(Sweep $sweep, CompanyContext $companies): int
    {
        // Each company's bookings inside its own company (companies plan,
        // Phase 1d). Stays are Rihla's alone, so only Rihla's pass sweeps
        // them; a second pass over the same stays under another company
        // would stamp that company on Rihla's guests' notices.
        $rihla = $companies->rihlaId();
        $withBookings = $companies->acrossEveryCompany(fn () => Booking::query()->distinct()->pluck('company_id'))->push($rihla);

        $raised = [];
        foreach ($companies->eachCompany($withBookings, fn (int $company): array => $sweep->run(includeStays: $company === $rihla)) as $counts) {
            foreach ($counts as $kind => $count) {
                $raised[$kind] = ($raised[$kind] ?? 0) + $count;
            }
        }

        $raised = array_filter($raised);

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
