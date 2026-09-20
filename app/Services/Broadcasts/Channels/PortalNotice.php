<?php

namespace App\Services\Broadcasts\Channels;

use App\Models\Booking;
use App\Models\BroadcastDelivery;
use App\Models\EmergencyBroadcast;
use App\Services\Broadcasts\BroadcastChannel;

/**
 * The channel that actually works today.
 *
 * A sent broadcast appears on the Pilgrim Portal and on every live family
 * link for that booking. No credentials, no provider, no account anybody
 * has to open — which is why it is the default and why §6.5's "even a
 * minimal version belongs in the first portal release" is satisfiable now
 * rather than after somebody signs an SMS contract.
 *
 * Nothing is written per booking: the broadcast row *is* the message, and
 * the portal pages read it. The delivery row records that it is visible to
 * this booking, which is a fact rather than a hope.
 */
final class PortalNotice implements BroadcastChannel
{
    public function key(): string
    {
        return 'portal';
    }

    /** Always. It needs nothing this host does not have. */
    public function isAvailable(): bool
    {
        return true;
    }

    public function whyUnavailable(): string
    {
        return '';
    }

    public function deliver(EmergencyBroadcast $broadcast, Booking $booking): array
    {
        // Honest about what "delivered" means here: it is on the page they
        // open, not in their hand. Somebody who never opens the portal has
        // not read it, and the wording says so rather than implying a push.
        return [
            'status' => BroadcastDelivery::DELIVERED,
            'detail' => 'Showing on their portal page and on any family link they have given out.',
        ];
    }
}
