<?php

namespace App\Services\Broadcasts\Channels;

use App\Models\Booking;
use App\Models\BroadcastDelivery;
use App\Models\EmergencyBroadcast;
use App\Services\Broadcasts\BroadcastChannel;

/**
 * SMS — **a seam, not an implementation**.
 *
 * Nobody has chosen a provider. In the Maldives that is a real decision
 * with a contract behind it, and guessing one would mean writing an
 * integration against an API this operator may never buy.
 *
 * So the shape is here and the behaviour is not, the same way the BML
 * payment driver holds the place for card payments. It reports itself
 * unavailable and says why, and `deliver()` is never reached while that is
 * true. If something calls it anyway it records `unavailable` rather than
 * throwing: an emergency broadcast must not fail as a whole because one
 * channel is missing.
 *
 * When a provider is chosen this file is where it goes, and nothing above
 * it changes.
 */
final class SmsChannel implements BroadcastChannel
{
    public function key(): string
    {
        return 'sms';
    }

    public function isAvailable(): bool
    {
        return filled(config('broadcasts.sms.provider'));
    }

    public function whyUnavailable(): string
    {
        return 'No SMS provider has been chosen, so nothing can be sent by text.';
    }

    public function deliver(EmergencyBroadcast $broadcast, Booking $booking): array
    {
        return [
            'status' => BroadcastDelivery::UNAVAILABLE,
            'detail' => $this->whyUnavailable(),
        ];
    }
}
