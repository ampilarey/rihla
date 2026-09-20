<?php

namespace App\Services\Broadcasts;

use App\Models\Booking;
use App\Models\EmergencyBroadcast;

/**
 * One way of reaching a booking — §6.5.
 *
 * The contract is deliberately small and has one unusual rule:
 * {@see isAvailable()} must be honest about this host, checked at call
 * time, not a constant somebody set once. A channel that reports itself
 * available and then writes to a log file produces a delivery row saying
 * "delivered" for a message nobody received, which is worse than having no
 * channel at all.
 */
interface BroadcastChannel
{
    /** 'portal', 'email', 'sms'. */
    public function key(): string;

    /**
     * Whether this host can actually send on this channel right now.
     *
     * Read from configuration every time. `MAIL_MAILER=log` is a valid
     * Laravel setup and a useless emergency channel, and the difference
     * has to be visible here rather than discovered after an incident.
     */
    public function isAvailable(): bool;

    /** Why not, in words a member of staff can act on. */
    public function whyUnavailable(): string;

    /**
     * Reach this booking. Returns a status and a sentence.
     *
     * @return array{status: string, detail: ?string}
     */
    public function deliver(EmergencyBroadcast $broadcast, Booking $booking): array;
}
