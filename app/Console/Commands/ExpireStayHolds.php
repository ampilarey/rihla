<?php

namespace App\Console\Commands;

use App\Models\RoomType;
use App\Models\Stay;
use App\Services\Stays\StayAllocator;
use Illuminate\Console\Command;

/**
 * Put lapsed stay holds back on the calendars they came from.
 *
 * **Correctness does not depend on this running.** A lapsed hold is
 * reclaimed inside the row lock the next hold takes, so a room anybody is
 * asking about heals itself. This exists for the quiet ones: a guesthouse
 * nobody opens for a week would otherwise show December as taken long after
 * the visitor stopped replying, and every later enquiry would be refused
 * nights that are free.
 *
 * That matters more here than it does for seats. A departure sells out and
 * somebody notices; a guesthouse that quietly stops being bookable just
 * receives no enquiries, and nothing about that looks like a fault.
 *
 * Suitable for a cPanel cron entry every fifteen minutes. If nobody ever
 * sets one up, nothing breaks — which is the point, on a host where nobody
 * watches the scheduler.
 */
class ExpireStayHolds extends Command
{
    protected $signature = 'stays:expire-holds';

    protected $description = 'Release stay holds whose deposit window has closed';

    public function handle(StayAllocator $allocator): int
    {
        // Only rooms that actually have something to reclaim. Locking every
        // room in the table to discover that none of them do is a lot of
        // row locks for nothing.
        $roomIds = Stay::query()->lapsed()->distinct()->pluck('room_type_id');

        if ($roomIds->isEmpty()) {
            $this->info('No stay holds have lapsed.');

            return self::SUCCESS;
        }

        $expired = 0;

        foreach (RoomType::whereKey($roomIds)->cursor() as $room) {
            $expired += $allocator->reclaim($room);
        }

        $this->info(sprintf(
            'Released %d stay(s) across %d room type(s).',
            $expired,
            $roomIds->count(),
        ));

        return self::SUCCESS;
    }
}
