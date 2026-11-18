<?php

namespace App\Console\Commands;

use App\Models\Departure;
use App\Models\SeatHold;
use App\Services\Booking\SeatAllocator;
use App\Support\CompanyContext;
use Illuminate\Console\Command;

/**
 * Put lapsed seat holds back on the departures they came from.
 *
 * **Correctness does not depend on this running.** A lapsed hold is
 * reclaimed inside the row lock the next booking takes, so a departure that
 * anyone is looking at heals itself. This exists for the quiet ones: a
 * departure nobody opens for a week would otherwise show seats as held long
 * after the customer closed the tab, and the seats-remaining bar would lie
 * to everybody who came next.
 *
 * Suitable for a cPanel cron entry every five minutes. If nobody ever sets
 * one up, nothing breaks — which is the point, on a host where nobody
 * watches the scheduler.
 */
class ExpireSeatHolds extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Release seat holds whose fifteen minutes are up';

    public function handle(SeatAllocator $allocator, CompanyContext $companies): int
    {
        // Every company's lapsed holds, each released inside its own
        // company (companies plan, Phase 1d): the scope would otherwise
        // leave every company but the console's default holding seats.
        $withLapsed = $companies->acrossEveryCompany(
            fn () => SeatHold::query()->lapsed()->distinct()->pluck('company_id'),
        );

        $released = $companies->eachCompany($withLapsed, fn (): array => $this->reclaim($allocator));

        $seats = array_sum(array_column($released, 0));
        $departures = array_sum(array_column($released, 1));

        if ($departures === 0) {
            $this->info('No seat holds have lapsed.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Released %d seat(s) across %d departure(s).', $seats, $departures));

        return self::SUCCESS;
    }

    /**
     * One company's lapsed holds.
     *
     * @return array{0: int, 1: int} seats released, departures touched
     */
    private function reclaim(SeatAllocator $allocator): array
    {
        // Only departures that actually have something to reclaim. Locking
        // every departure in the table to discover that none of them do is
        // a lot of row locks for nothing.
        $departureIds = SeatHold::query()->lapsed()->distinct()->pluck('departure_id');

        $seats = 0;

        foreach (Departure::whereKey($departureIds)->cursor() as $departure) {
            $seats += $allocator->reclaim($departure);
        }

        return [$seats, $departureIds->count()];
    }
}
