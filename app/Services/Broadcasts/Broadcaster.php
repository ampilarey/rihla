<?php

namespace App\Services\Broadcasts;

use App\Models\Booking;
use App\Models\BroadcastDelivery;
use App\Models\EmergencyBroadcast;
use App\Models\User;
use App\Services\Broadcasts\Channels\EmailChannel;
use App\Services\Broadcasts\Channels\PortalNotice;
use App\Services\Broadcasts\Channels\SmsChannel;
use Illuminate\Support\Collection;

/**
 * Sending one broadcast to a whole departure — §6.5.
 *
 * ## One channel failing must not stop the others
 *
 * The whole point of a broadcast is reach. If SMS is not set up, that is a
 * row saying so next to each person's name, not an exception that stops
 * the portal notice from going out. Every channel is attempted for every
 * booking and every outcome is written down.
 *
 * ## Only confirmed bookings
 *
 * A draft booking is not somebody who is on the trip. Messaging them in an
 * emergency would reach a stranger with news about a group they are not
 * travelling with.
 *
 * ## It is idempotent per (broadcast, booking, channel)
 *
 * A retry after a partial send updates rather than adding rows, so the
 * delivery list stays a list of people rather than a list of attempts —
 * and the question "who did we reach" keeps its answer.
 */
final class Broadcaster
{
    /** @return Collection<int, BroadcastChannel> */
    public function channels(): Collection
    {
        /** @var array<string, BroadcastChannel> $available */
        $available = [
            'portal' => new PortalNotice,
            'email' => new EmailChannel,
            'sms' => new SmsChannel,
        ];

        /** @var Collection<int, BroadcastChannel> $channels */
        $channels = collect();

        foreach ((array) config('broadcasts.channels', ['portal']) as $key) {
            if (isset($available[$key])) {
                $channels->push($available[$key]);
            }
        }

        return $channels;
    }

    /**
     * Send it, and write down what happened to every person on every
     * channel.
     */
    public function send(EmergencyBroadcast $broadcast, ?User $actor = null): EmergencyBroadcast
    {
        $bookings = $broadcast->departure->bookings()
            ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->with('customer')
            ->get();

        foreach ($this->channels() as $channel) {
            foreach ($bookings as $booking) {
                $result = $channel->isAvailable()
                    ? $channel->deliver($broadcast, $booking)
                    : ['status' => BroadcastDelivery::UNAVAILABLE, 'detail' => $channel->whyUnavailable()];

                BroadcastDelivery::updateOrCreate(
                    [
                        'emergency_broadcast_id' => $broadcast->getKey(),
                        'booking_id' => $booking->getKey(),
                        'channel' => $channel->key(),
                    ],
                    ['status' => $result['status'], 'detail' => $result['detail'] ?? null],
                );
            }
        }

        // Stamped after the attempt, so a broadcast that threw halfway is
        // still a draft rather than a thing that claims to have gone out.
        $broadcast->forceFill([
            'sent_at' => $broadcast->sent_at ?? now(),
            'sent_by' => $broadcast->sent_by ?? ($actor ?? auth()->user())?->getKey(),
        ])->save();

        return $broadcast->fresh(['deliveries']);
    }

    /**
     * What a member of staff should be told before they press send.
     *
     * Named channels and the reason each unavailable one is unavailable —
     * so the decision to phone people instead is made *before* the
     * emergency, not discovered after it.
     *
     * @return list<array{channel: string, available: bool, why: string}>
     */
    public function readiness(): array
    {
        return $this->channels()
            ->map(fn (BroadcastChannel $channel): array => [
                'channel' => $channel->key(),
                'available' => $channel->isAvailable(),
                'why' => $channel->isAvailable() ? '' : $channel->whyUnavailable(),
            ])
            ->all();
    }
}
