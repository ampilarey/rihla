<?php

namespace App\Services\Operations;

use App\Models\BookingTraveller;
use App\Models\Customer;
use App\Models\FamilyAccess;
use Illuminate\Support\Collection;

/**
 * One family on one departure: everybody one person booked onto it,
 * across however many of their bookings, with its head first.
 *
 * Built by {@see Families} and never stored. The head is a flag that was
 * already there (`booking_travellers.is_lead`, "the person operations
 * calls"), the relationships are `travellers.relationship`, and the family
 * is nothing but who booked whom — so there is no table to keep in step
 * with the bookings, and a family cannot disagree with them.
 *
 * Not the Family Portal ({@see FamilyAccess}): that is the
 * page a pilgrim gives relatives at home. This is the people travelling.
 */
final class TravellingFamily
{
    /**
     * @param  Collection<int, BookingTraveller>  $members  head first, with traveller and booking loaded
     */
    public function __construct(
        public readonly Customer $booker,
        public readonly BookingTraveller $head,
        public readonly Collection $members,
    ) {}

    /** "Aminath Ali's family": named for the person who booked it, as the bookings are. */
    public function name(): string
    {
        return $this->booker->name.'\'s family';
    }

    public function size(): int
    {
        return $this->members->count();
    }

    public function isHead(BookingTraveller $member): bool
    {
        return $member->getKey() === $this->head->getKey();
    }

    /** The member a traveller is, if they are in this family. */
    public function memberFor(int $travellerId): ?BookingTraveller
    {
        return $this->members->first(fn (BookingTraveller $member): bool => (int) $member->traveller_id === $travellerId);
    }

    /**
     * The number to ring about this family: the head's own, or else the
     * booker's — who, when the head has no phone on file, is the person
     * that number reaches.
     */
    public function contactPhone(): ?string
    {
        $own = trim((string) $this->head->traveller->phone);

        if ($own !== '') {
            return $own;
        }

        $booker = trim((string) $this->booker->phone);

        return $booker === '' ? null : $booker;
    }

    /** Who {@see contactPhone()} reaches. */
    public function contactName(): string
    {
        return trim((string) $this->head->traveller->phone) !== ''
            ? $this->head->traveller->full_name
            : $this->booker->name;
    }

    /** The number to ring about one member: their own, or else the family's. */
    public function phoneFor(BookingTraveller $member): ?string
    {
        $own = trim((string) $member->traveller->phone);

        return $own !== '' ? $own : $this->contactPhone();
    }

    /**
     * Whose number {@see phoneFor()} is: 'own', 'head' or 'booker', or null
     * when there is none — so a screen can say who will answer.
     */
    public function phoneSourceFor(BookingTraveller $member): ?string
    {
        return match (true) {
            trim((string) $member->traveller->phone) !== '' => 'own',
            trim((string) $this->head->traveller->phone) !== '' => 'head',
            trim((string) $this->booker->phone) !== '' => 'booker',
            default => null,
        };
    }

    /**
     * Whether the head is the person who booked: said so, or the same name.
     * Then the booker's phone is the head's own.
     */
    public function headIsBooker(): bool
    {
        return $this->head->traveller->relationship === 'self'
            || mb_strtolower(trim($this->head->traveller->full_name)) === mb_strtolower(trim($this->booker->name));
    }

    /**
     * Who to ring about this family, in words: "Ahmed Ali, the head, on
     * 7771234", or, when the head has no phone of their own on file, the
     * booker's number and a note that the head's is missing.
     */
    public function contactWords(): ?string
    {
        $phone = $this->contactPhone();

        if ($phone === null) {
            return null;
        }

        $head = $this->head->traveller->full_name;

        if (trim((string) $this->head->traveller->phone) !== '' || $this->headIsBooker()) {
            return $head.', the head, on '.$phone;
        }

        return $this->booker->name.', who booked, on '.$phone.' · no phone on file for '.$head.', the head';
    }

    /** "the person who booked", "Aminath Ali's son", or null while nobody has said. */
    public function relationOf(BookingTraveller $member): ?string
    {
        return $member->traveller->relationTo($this->booker->name);
    }

    /** @return list<int> the bookings this family is on, oldest first */
    public function bookingIds(): array
    {
        return $this->members
            ->map(fn (BookingTraveller $member): int => (int) $member->booking_id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
