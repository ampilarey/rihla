<?php

namespace App\Services\Stays;

use App\Exceptions\DeskRefusal;
use App\Exceptions\NotSoldToAudience;
use App\Exceptions\RoomNotAvailable;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\PropertyUnit;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Support\Audience;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A booking the host takes themselves — a phone call, a walk-in, a night
 * sold on another site — §16.6 (`Pages\NewBooking`), §16.10.
 *
 * Through the same path as every other stay — `StayBooking::request()`,
 * then the allocator's hold and confirm under its lock — so a direct
 * booking cannot take a room the marketplace already sold, and is refused
 * with the same sentence. The host has already said yes to their own
 * guest, so it is confirmed at once. It is never `marketplace`, so it
 * carries no commission (§16.9).
 *
 * The one thing a direct booking may do that no other can is **agree a
 * different price**: the host is selling their own room. The quote is kept
 * in the snapshot beside the agreed total, so a report can still tell a
 * discount from a rate.
 */
class DirectBooking
{
    public const NOT_MARKETPLACE = 'A booking you take yourself cannot be marked as coming from the Rihla marketplace.';

    public function __construct(
        private readonly StayBooking $booking,
        private readonly StayAllocator $allocator,
        private readonly StayDesk $desk,
    ) {}

    /**
     * @param  array{name: string, phone: string, email?: ?string}  $guest
     *
     * @throws RoomNotAvailable
     * @throws NotSoldToAudience
     * @throws DeskRefusal
     */
    public function take(
        Partner $host,
        RoomType $room,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        array $guest,
        int $adults,
        int $children = 0,
        string $audience = Audience::TOURIST,
        string $source = 'phone',
        ?Money $agreedTotal = null,
        ?Money $depositTaken = null,
        string $depositMethod = 'cash',
        ?PropertyUnit $unit = null,
        ?string $notes = null,
        ?User $by = null,
    ): Stay {
        if ((int) $room->property?->partner_id !== (int) $host->getKey()) {
            throw new DeskRefusal('That room is not one of yours.');
        }

        if ($source === Commission::MARKETPLACE) {
            throw new DeskRefusal(self::NOT_MARKETPLACE);
        }

        return DB::transaction(function () use ($host, $room, $checkIn, $checkOut, $guest, $adults, $children, $audience, $source, $agreedTotal, $depositTaken, $depositMethod, $unit, $notes, $by): Stay {
            $customer = Customer::create([
                'name' => $guest['name'],
                'phone' => $guest['phone'],
                'email' => $guest['email'] ?? null,
            ]);

            $stay = $this->booking->request(
                $customer,
                $room,
                $checkIn,
                $checkOut,
                adults: $adults,
                children: $children,
                details: [
                    'special_requests' => $notes,
                    'source' => $source,
                    'created_via' => Stay::VIA_HOST,
                    'created_by' => $by?->getKey(),
                ],
                audience: $audience,
            );

            if ($agreedTotal !== null && $agreedTotal->minor !== $stay->total_minor) {
                if ($agreedTotal->currency !== $stay->currency) {
                    throw new DeskRefusal('Agree the price in '.$stay->currency.', the currency this room is sold in.');
                }

                $stay->forceFill([
                    'total_minor' => $agreedTotal->minor,
                    'rate_snapshot' => [
                        ...(array) $stay->rate_snapshot,
                        'agreed' => ['quoted_total_minor' => $stay->total_minor, 'total_minor' => $agreedTotal->minor],
                    ],
                ])->save();
            }

            if ($unit !== null) {
                $this->desk->assertUnitFits($stay, $unit, forTheNights: true);
                $stay->forceFill(['unit_id' => $unit->getKey()])->save();
            }

            // The host has said yes to their own guest: held and confirmed
            // under the allocator's lock, which is what refuses a room that
            // has gone since the quote.
            $this->allocator->hold($stay);
            $stay = $this->allocator->confirm($stay);

            if ($depositTaken !== null && $depositTaken->minor > 0) {
                $this->desk->recordHostPayment($stay, $host, $depositTaken, $depositMethod, 'Deposit taken with the booking', $by);
            }

            return $stay->refresh();
        });
    }
}
