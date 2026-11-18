<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Traveller;
use App\Services\Operations\TravellingFamily;

/**
 * Who the office deals with about a booking, and on which number —
 * companies plan, Phase 3a (Hijaz parity).
 *
 * The booking's own contact for this trip if it names one, else the
 * family's contact, else the person who booked. A named contact must be
 * one of the booker's own travellers; anybody else is ignored rather than
 * messaged. Their number is their own phone, or the booker's when they
 * have none on file, and the words say whose it is.
 *
 * Not the head of the family ({@see TravellingFamily}):
 * the head holds the papers and is rung on the journey; the contact pays,
 * signs and gets the office's messages. Often the same person; often not.
 */
final class OfficeContact
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $phone,
        public readonly ?Traveller $traveller,
        public readonly bool $forThisBookingOnly,
    ) {}

    public static function forBooking(Booking $booking): self
    {
        $customer = $booking->customer;
        $own = self::traveller($customer, $booking->contact_traveller_id);

        if ($own !== null) {
            return self::of($customer, $own, true);
        }

        return self::forCustomer($customer);
    }

    public static function forCustomer(Customer $customer): self
    {
        $traveller = self::traveller($customer, $customer->contact_traveller_id);

        return $traveller === null
            ? new self($customer->name, filled($customer->phone) ? (string) $customer->phone : null, null, false)
            : self::of($customer, $traveller, false);
    }

    /** "Aishath Ali on 7771234", saying whose number it is when it is the booker's. */
    public function words(Customer $customer): ?string
    {
        if ($this->phone === null) {
            return null;
        }

        $borrowed = $this->traveller !== null && blank($this->traveller->phone);

        return $this->name.' on '.$this->phone
            .($borrowed ? ' (the number of '.$customer->name.', who booked; none on file for '.$this->name.')' : '')
            .($this->forThisBookingOnly ? ' · for this booking' : '');
    }

    private static function of(Customer $customer, Traveller $traveller, bool $forThisBookingOnly): self
    {
        $phone = filled($traveller->phone) ? (string) $traveller->phone : (filled($customer->phone) ? (string) $customer->phone : null);

        return new self($traveller->full_name, $phone, $traveller, $forThisBookingOnly);
    }

    private static function traveller(Customer $customer, ?int $id): ?Traveller
    {
        if ($id === null) {
            return null;
        }

        return $customer->travellers()->whereKey($id)->first();
    }
}
