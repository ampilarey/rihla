<?php

namespace Modules\Transport\Console\Commands;

use Illuminate\Console\Command;
use Modules\Transport\Services\TransportDesk;

/**
 * Give back the seats of boat bookings held and never confirmed —
 * docs/TRANSPORT_PLAN.md. A new booking reclaims its own sailing's lapsed
 * holds anyway; this keeps a sailing nobody is booking from looking full.
 */
class ExpireBoatHolds extends Command
{
    protected $signature = 'transport:expire-holds';

    protected $description = 'Lapse boat bookings whose hold ran out and free their seats';

    public function handle(TransportDesk $desk): int
    {
        $this->info('Freed '.$desk->reclaimLapsed().' seat(s).');

        return self::SUCCESS;
    }
}
