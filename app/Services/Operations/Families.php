<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Departure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Families — batch C from Hijaz, as the owner chose it: a family is the
 * travellers one person booked onto one departure. They are shown as one
 * group on the booking, roomed and bussed together unless somebody decides
 * otherwise, and headed by one of them, who holds the family's documents
 * and is the one the office and the tour leader ring.
 *
 * Grouped by the booker across their bookings, not by booking, because the
 * commonest way a family arrives in two pieces is a relative added a week
 * later on a second booking: grouped by booking, the two halves could sit
 * on different coaches with nothing to say so.
 *
 * Nothing here is stored. See {@see TravellingFamily}.
 */
final class Families
{
    /**
     * Bookings whose people are travelling — holding a seat, confirmed, or
     * home — the same set every list and the bus candidates use.
     *
     * @var list<string>
     */
    public const TRAVELLING = [Booking::HELD, Booking::CONFIRMED, Booking::COMPLETED];

    /**
     * Every family on a departure, keyed by the booker's id.
     *
     * @return Collection<int, TravellingFamily>
     */
    public function onDeparture(Departure $departure): Collection
    {
        return $this->group(
            BookingTraveller::query()
                ->whereIn(
                    'booking_id',
                    Booking::query()->where('departure_id', $departure->getKey())->whereIn('status', self::TRAVELLING)->select('id'),
                )
                ->with(['traveller', 'booking.customer'])
                ->get(),
        );
    }

    /**
     * The family a booking belongs to: its own travellers, and anybody the
     * same person has booked onto the same departure on another booking
     * that is travelling. The booking itself counts whatever its status, so
     * a draft being assembled at the desk shows the family it is joining.
     */
    public function forBooking(Booking $booking): ?TravellingFamily
    {
        $members = BookingTraveller::query()
            ->whereIn(
                'booking_id',
                Booking::query()
                    ->where('departure_id', $booking->departure_id)
                    ->where('customer_id', $booking->customer_id)
                    ->where(fn ($query) => $query->whereIn('status', self::TRAVELLING)->orWhere('id', $booking->getKey()))
                    ->select('id'),
            )
            ->with(['traveller', 'booking.customer'])
            ->get();

        return $members->isEmpty() ? null : $this->build($members);
    }

    /** The family one member belongs to. */
    public function of(BookingTraveller $member): TravellingFamily
    {
        return $this->forBooking($member->booking)
            ?? $this->build(new Collection([$member->loadMissing(['traveller', 'booking.customer'])]));
    }

    /**
     * Members into families, by who booked them.
     *
     * @param  Collection<int, BookingTraveller>  $members  with traveller and booking.customer loaded
     * @return Collection<int, TravellingFamily>
     */
    public function group(Collection $members): Collection
    {
        return $members
            ->groupBy(fn (BookingTraveller $member): int => (int) $member->booking->customer_id)
            ->map(fn (Collection $family): TravellingFamily => $this->build($family));
    }

    /**
     * One booker's members as a family, head first and the rest by name.
     *
     * @param  Collection<int, BookingTraveller>  $members
     */
    public function build(Collection $members): TravellingFamily
    {
        $head = $this->headOf($members);

        $ordered = $members
            ->sortBy(fn (BookingTraveller $member): string => ($member->getKey() === $head->getKey() ? '0' : '1').mb_strtolower($member->traveller->full_name))
            ->values();

        return new TravellingFamily($head->booking->customer, $head, $ordered);
    }

    /**
     * Make this person the head of their family: their flag on and every
     * other member's off, across every booking the family spans, so the
     * family has one head and not one per booking.
     */
    public function makeHead(BookingTraveller $member): TravellingFamily
    {
        DB::transaction(function () use ($member): void {
            foreach ($this->of($member)->members as $other) {
                $isHead = $other->getKey() === $member->getKey();

                if ((bool) $other->is_lead !== $isHead) {
                    $other->update(['is_lead' => $isHead]);
                }
            }
        });

        return $this->of($member->refresh());
    }

    /**
     * A selection widened to whole families, from the people who may come
     * along — "bring the rest of their family", which the bus and room
     * screens do unless it is turned off. Only somebody in the pool is
     * brought: a sister already on the bus, or already in a room in this
     * hotel, is left where she is.
     *
     * @param  list<int>  $selected  booking traveller ids
     * @param  Collection<int, BookingTraveller>  $pool  with booking loaded
     * @return list<int>
     */
    public function widen(array $selected, Collection $pool): array
    {
        $bookers = $pool
            ->filter(fn (BookingTraveller $member): bool => in_array((int) $member->getKey(), $selected, true))
            ->map(fn (BookingTraveller $member): int => (int) $member->booking->customer_id)
            ->unique();

        $family = $pool
            ->filter(fn (BookingTraveller $member): bool => $bookers->contains((int) $member->booking->customer_id))
            ->map(fn (BookingTraveller $member): int => (int) $member->getKey());

        return collect($selected)
            ->map(fn (int $id): int => $id)
            ->merge($family)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Who heads a family: the lead on its earliest booking; else whoever
     * booked it, if they are travelling; else the first person booked.
     *
     * @param  Collection<int, BookingTraveller>  $members
     */
    private function headOf(Collection $members): BookingTraveller
    {
        $inOrder = $members
            ->sortBy(fn (BookingTraveller $member): string => sprintf('%012d-%012d', $member->booking_id, $member->getKey()))
            ->values();

        $head = $inOrder->first(fn (BookingTraveller $member): bool => (bool) $member->is_lead)
            ?? $inOrder->first(fn (BookingTraveller $member): bool => $member->traveller->relationship === 'self')
            ?? $inOrder->first();

        if (! $head instanceof BookingTraveller) {
            throw new InvalidArgumentException('A family has at least one traveller.');
        }

        return $head;
    }
}
