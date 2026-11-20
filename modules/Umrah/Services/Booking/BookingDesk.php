<?php

namespace Modules\Umrah\Services\Booking;

use App\Models\Customer;
use App\Models\User;
use App\Support\Audience;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Umrah\Exceptions\NoSeatsAvailable;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\BookingLine;
use Modules\Umrah\Models\BookingTraveller;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\Discount;
use Modules\Umrah\Models\PriceTier;
use Modules\Umrah\Models\Traveller;

/**
 * Taking a booking at the desk — a walk-in, a phone call — and the money
 * decisions on one afterwards: extras, fees and discounts as lines.
 *
 * Until this existed a booking came only from the public checkout, and a
 * booking somebody made at the counter was typed into the website by staff
 * pretending to be the customer. The desk does what the checkout does, in
 * one transaction, through the same seat allocator and at the same prices:
 * the seats are taken under the departure's row lock, the product is
 * frozen on the booking, and every traveller is priced by their room and
 * their age on the departure day.
 *
 * Everything is a line. A discount is a negative line with a {@see Discount}
 * row behind it saying why and who; the booking's total is the sum of its
 * lines, as it always was.
 */
final class BookingDesk
{
    public function __construct(private readonly SeatAllocator $seats) {}

    /**
     * Open a booking for a customer's travellers.
     *
     * The seats are held for the staff extension window (a day by default)
     * rather than a checkout's fifteen minutes: somebody who booked at the
     * counter is going to the bank, not sitting at a form. Staff extend or
     * cancel from the booking as usual.
     *
     * @param  list<array{traveller_id: int, occupancy: string}>  $party
     *
     * @throws NoSeatsAvailable
     * @throws InvalidArgumentException when the party is empty, a traveller is not the customer's, or a room has no price
     */
    public function open(Departure $departure, Customer $customer, array $party, string $audience = Audience::LOCAL, ?string $notes = null): Booking
    {
        if ($party === []) {
            throw new InvalidArgumentException('A booking needs at least one traveller.');
        }

        if (! Audience::isValid($audience)) {
            $audience = Audience::LOCAL;
        }

        $departure->loadMissing(['priceTiers', 'package']);

        return DB::transaction(function () use ($departure, $customer, $party, $audience, $notes): Booking {
            $booking = Booking::query()->create([
                'customer_id' => $customer->getKey(),
                'departure_id' => $departure->getKey(),
                'seats' => count($party),
                'currency' => $departure->leadPriceFor($audience)->currency ?? 'MVR',
                'notes' => $notes,
            ]);

            // Before the travellers, so a full departure refuses before
            // anything else is written; the transaction takes it all back.
            $this->seats->hold(
                $departure,
                count($party),
                $booking,
                now()->addMinutes((int) config('booking.holds.extension_minutes', 1440)),
            );

            foreach ($party as $index => $member) {
                $traveller = Traveller::query()
                    ->where('customer_id', $customer->getKey())
                    ->find((int) $member['traveller_id']);

                if ($traveller === null) {
                    throw new InvalidArgumentException('Every traveller on a booking has to belong to the customer booking it.');
                }

                $this->seat($booking, $departure, $traveller, (string) $member['occupancy'], $audience, isLead: $index === 0);
            }

            $booking->recalculateTotal();
            $booking->transitionTo(Booking::HELD, 'Opened at the desk.');

            return $booking->refresh();
        });
    }

    /**
     * The tier a traveller's room and age cost on a departure, falling back
     * to the adult price when the departure prices no children — the same
     * rule as the checkout, so a child is never cheaper at the desk than
     * on the website or dearer.
     */
    public function tierFor(Departure $departure, string $occupancy, string $paxType, string $audience = Audience::LOCAL): ?PriceTier
    {
        return $departure->tierFor($occupancy, $paxType, $audience)
            ?? $departure->tierFor($occupancy, PriceTier::ADULT, $audience);
    }

    /**
     * An extra or a fee, as a line. Whole units in; the line holds minor.
     *
     * @throws InvalidArgumentException
     */
    public function addLine(Booking $booking, string $type, string $description, int $unitMajor, int $quantity = 1): BookingLine
    {
        if (! in_array($type, [BookingLine::EXTRA, BookingLine::FEE], true)) {
            throw new InvalidArgumentException('Only an extra or a fee can be added here; a discount has its own door.');
        }

        $this->mustBeLive($booking);

        if ($unitMajor < 1 || $quantity < 1) {
            throw new InvalidArgumentException('A charge must be worth something.');
        }

        return DB::transaction(function () use ($booking, $type, $description, $unitMajor, $quantity): BookingLine {
            $unit = Money::ofMajor($unitMajor, $booking->currency);

            $line = $this->line($booking, $type, $description, $unit->minor, $quantity);

            $booking->recalculateTotal();

            return $line;
        });
    }

    /** Remove an extra or a fee. Seats and discounts have their own ways out. */
    public function removeLine(BookingLine $line): void
    {
        if (! in_array($line->type, [BookingLine::EXTRA, BookingLine::FEE], true)) {
            throw new InvalidArgumentException('Only an extra or a fee can be removed; a discount is taken back, and a seat is cancelled.');
        }

        $booking = $line->booking;
        $this->mustBeLive($booking);

        DB::transaction(function () use ($line, $booking): void {
            $line->delete();
            $booking->recalculateTotal();
        });
    }

    /**
     * A discount: a negative line, and the row that says why and who.
     *
     * Worked out against the total as it stands, so a second discount is a
     * share of what is left and not of the original price, and frozen as
     * an amount: a percentage is a way of arriving at a number, not a rule
     * that keeps applying as lines change.
     *
     * @param  string  $value  whole rufiyaa for a fixed discount, a percentage otherwise
     *
     * @throws InvalidArgumentException
     */
    public function discount(Booking $booking, string $kind, string $value, string $reason, ?User $approver = null): Discount
    {
        if (! in_array($kind, Discount::KINDS, true)) {
            throw new InvalidArgumentException('A discount is an amount or a percentage.');
        }

        $this->mustBeLive($booking);

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A discount needs a reason.');
        }

        if (! is_numeric($value) || (float) $value <= 0) {
            throw new InvalidArgumentException('A discount must be worth something.');
        }

        return DB::transaction(function () use ($booking, $kind, $value, $reason, $approver): Discount {
            $total = $booking->refresh()->total_minor;

            $minor = $kind === Discount::PERCENT
                ? (int) round($total * (float) $value / 100)
                : Money::ofMajor((int) $value, $booking->currency)->minor;

            if ($minor < 1) {
                throw new InvalidArgumentException('A discount must be worth something.');
            }

            if ($minor > $total) {
                throw new InvalidArgumentException('A discount cannot be more than the '.Money::ofMinor($total, $booking->currency)->format().' the booking comes to.');
            }

            $line = $this->line($booking, BookingLine::DISCOUNT, 'Discount: '.trim($reason), -$minor);

            $discount = $booking->discounts()->create([
                'booking_line_id' => $line->getKey(),
                'kind' => $kind,
                'value' => $kind === Discount::PERCENT ? (string) (float) $value : (string) (int) $value,
                'amount_minor' => -$minor,
                'reason' => trim($reason),
                'approved_by' => ($approver ?? Auth::user())?->getKey(),
                'approved_at' => now(),
            ]);

            $booking->recalculateTotal();

            return $discount;
        });
    }

    /** Take a discount back: the line goes, the row stays and says so. */
    public function reverseDiscount(Discount $discount): void
    {
        if ($discount->isReversed()) {
            return;
        }

        $booking = $discount->booking;
        $this->mustBeLive($booking);

        DB::transaction(function () use ($discount, $booking): void {
            $discount->line?->delete();
            $discount->forceFill(['reversed_at' => now()])->save();
            $booking->recalculateTotal();
        });
    }

    /**
     * Price everybody registered on a departure before it had a price for
     * their room (companies plan, Phase 3b, Hijaz parity), by room and by
     * their age on the departure day, or today while it has no date. A fare
     * already set is never touched. Returns how many were priced.
     */
    public function priceWaiting(Departure $departure): int
    {
        $departure->unsetRelation('priceTiers');
        $departure->loadMissing('priceTiers');
        $priced = 0;

        $waiting = BookingTraveller::query()
            ->where('awaits_price', true)
            ->whereHas('booking', fn ($query) => $query->where('departure_id', $departure->getKey())->whereIn('status', [Booking::HELD, Booking::CONFIRMED]))
            ->with(['booking', 'traveller'])
            ->get();

        foreach ($waiting as $line) {
            $booking = $line->booking;
            $audience = (string) ($line->awaits_audience ?? Audience::LOCAL);
            $paxType = PriceTier::paxTypeForAge($line->traveller->ageOn($departure->date_start ?? today()));
            $tier = $this->tierFor($departure, (string) $line->occupancy, $paxType, $audience);

            if ($tier === null) {
                continue;
            }

            DB::transaction(function () use ($booking, $line, $tier, &$priced): void {
                // The booking's currency follows its first price.
                if (! $booking->travellers()->where('awaits_price', false)->exists()) {
                    $booking->forceFill(['currency' => $tier->currency])->save();
                }

                $line->forceFill([
                    'pax_type' => $tier->pax_type,
                    'price_tier_id' => $tier->getKey(),
                    'amount_minor' => $tier->amount_minor,
                    'awaits_price' => false,
                ])->save();

                $booking->lines()->create([
                    'booking_traveller_id' => $line->getKey(),
                    'type' => BookingLine::SEAT,
                    'description' => $line->traveller->full_name,
                    'unit_amount_minor' => $tier->amount_minor,
                    'amount_minor' => $tier->amount_minor,
                    'currency' => $booking->currency,
                ]);

                $booking->recalculateTotal();
                $priced++;
            });
        }

        return $priced;
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function seat(Booking $booking, Departure $departure, Traveller $traveller, string $occupancy, string $audience, bool $isLead): void
    {
        if (! in_array($occupancy, PriceTier::OCCUPANCIES, true)) {
            throw new InvalidArgumentException('Choose a room for '.$traveller->full_name.'.');
        }

        $paxType = PriceTier::paxTypeForAge($traveller->ageOn($departure->date_start ?? today()));
        $tier = $this->tierFor($departure, $occupancy, $paxType, $audience);

        // Registration before a price (companies plan, Phase 3b, Hijaz
        // parity): a departure with no price at all yet for this audience
        // takes the name and prices it when the price is set. One that has
        // prices but not this room still refuses, so a mistyped room is
        // never silently left unpriced.
        if ($tier === null && ! $departure->priceTiers->contains(fn (PriceTier $t): bool => $t->audience === $audience)) {
            $booking->travellers()->create([
                'traveller_id' => $traveller->getKey(),
                'occupancy' => $occupancy,
                'pax_type' => $paxType,
                'amount_minor' => 0,
                'is_lead' => $isLead,
                'awaits_price' => true,
                'awaits_audience' => $audience,
            ]);

            return;
        }

        if ($tier === null) {
            throw new InvalidArgumentException(sprintf(
                'This departure has no %s price for a %s room.',
                Audience::label($audience),
                $occupancy,
            ));
        }

        $line = $booking->travellers()->create([
            'traveller_id' => $traveller->getKey(),
            'occupancy' => $occupancy,
            'pax_type' => $tier->pax_type,
            'price_tier_id' => $tier->getKey(),
            'amount_minor' => $tier->amount_minor,
            'is_lead' => $isLead,
        ]);

        $booking->lines()->create([
            'booking_traveller_id' => $line->getKey(),
            'type' => BookingLine::SEAT,
            'description' => $traveller->full_name,
            'unit_amount_minor' => $tier->amount_minor,
            'amount_minor' => $tier->amount_minor,
            'currency' => $booking->currency,
        ]);
    }

    private function line(Booking $booking, string $type, string $description, int $unitMinor, int $quantity = 1): BookingLine
    {
        return $booking->lines()->create([
            'type' => $type,
            'description' => mb_substr($description, 0, 255),
            'quantity' => $quantity,
            'unit_amount_minor' => $unitMinor,
            'amount_minor' => $unitMinor * $quantity,
            'currency' => $booking->currency,
            'sort_order' => (int) $booking->lines()->max('sort_order') + 1,
        ]);
    }

    private function mustBeLive(Booking $booking): void
    {
        if (! $booking->isLive()) {
            throw new InvalidArgumentException('This booking is '.$booking->status.'; nothing more can be charged to it.');
        }
    }
}
