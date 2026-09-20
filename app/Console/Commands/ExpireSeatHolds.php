<?php

namespace App\Console\Commands;

use App\Models\Departure;
use App\Models\SeatHold;
use App\Services\Booking\SeatAllocator;
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

    public function handle(SeatAllocator $allocator): int
    {
        // Only departures that actually have something to reclaim. Locking
        // every departure in the table to discover that none of them do is
        // a lot of row locks for nothing.
        $departureIds = SeatHold::query()->lapsed()->distinct()->pluck('departure_id');

        if ($departureIds->isEmpty()) {
            $this->info('No seat holds have lapsed.');

            return self::SUCCESS;
        }

        $seats = 0;

        foreach (Departure::whereKey($departureIds)->cursor() as $departure) {
            $seats += $allocator->reclaim($departure);
        }

        $this->info(sprintf(
            'Released %d seat(s) across %d departure(s).',
            $seats,
            $departureIds->count(),
        ));

        return self::SUCCESS;
    }
}
