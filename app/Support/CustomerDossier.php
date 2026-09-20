<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Enquiry;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\ScholarQuestion;
use Illuminate\Support\Collection;

/**
 * Everything this office knows about one person — §8.1's customer 360.
 *
 * ## Assembled on read, never stored
 *
 * The same reasoning as {@see DepartureReadiness} and {@see StudyPlan}. A
 * stored summary of somebody's history is wrong from the first payment
 * after it was written, and a customer screen that is quietly out of date
 * is worse than one that is slow.
 *
 * ## It gathers; it does not judge
 *
 * No score, no segment, no "value" band. A number that ranks customers
 * ends up deciding who gets a phone call, and nobody asked for this system
 * to make that decision. What it answers is the questions the office
 * actually asks on the phone: have they travelled with us, what do they
 * owe, what did we quote them, what did they ask us, and who is supposed
 * to be doing something about it.
 */
final class CustomerDossier
{
    private function __construct(
        public readonly Customer $customer,
        /** @var Collection<int, Booking> */
        public readonly Collection $bookings,
        /** @var Collection<int, Enquiry> */
        public readonly Collection $enquiries,
        /** @var Collection<int, Quotation> */
        public readonly Collection $quotations,
        /** @var Collection<int, CrmTask> */
        public readonly Collection $openTasks,
        /** @var Collection<int, ScholarQuestion> */
        public readonly Collection $questions,
        public readonly ?Departure $lastJourney,
    ) {}

    public static function build(Customer $customer): self
    {
        $bookings = $customer->bookings()
            ->with(['departure.package', 'payments', 'travellers.traveller'])
            ->orderByDesc('created_at')
            ->get();

        $enquiries = $customer->enquiries()
            ->with(['owner', 'package'])
            ->orderByDesc('created_at')
            ->get();

        return new self(
            customer: $customer,
            bookings: $bookings,
            enquiries: $enquiries,
            quotations: Quotation::whereIn('enquiry_id', $enquiries->pluck('id'))
                ->with(['package', 'departure'])
                ->orderByDesc('created_at')
                ->get(),
            openTasks: CrmTask::query()
                ->open()
                ->where(function ($query) use ($customer, $bookings, $enquiries) {
                    $query
                        ->where(fn ($q) => $q->where('about_type', Customer::class)->where('about_id', $customer->getKey()))
                        ->orWhere(fn ($q) => $q->where('about_type', Booking::class)->whereIn('about_id', $bookings->pluck('id')))
                        ->orWhere(fn ($q) => $q->where('about_type', Enquiry::class)->whereIn('about_id', $enquiries->pluck('id')));
                })
                ->with('owner')
                ->orderBy('due_on')
                ->get(),
            questions: ScholarQuestion::whereIn('booking_id', $bookings->pluck('id'))
                ->with('scholar')
                ->orderByDesc('created_at')
                ->get(),
            lastJourney: $customer->lastDeparted(),
        );
    }

    /**
     * How many journeys have actually happened, not how many were booked.
     *
     * The same status list as {@see Customer::lastDeparted()}, because the
     * two numbers sit next to each other on one screen. They did not
     * match once, and the page said "has not travelled with us yet"
     * directly above "last travelled 14 months ago".
     */
    public function journeysTaken(): int
    {
        return $this->bookings
            ->filter(fn (Booking $booking): bool => $booking->departure !== null
                && $booking->departure->date_start->isPast()
                && in_array($booking->status, Customer::TRAVELLED_ON, true))
            ->count();
    }

    /**
     * What they still owe, across every live booking.
     *
     * Summed in integer minor units and keyed by currency — never added
     * across currencies, which is the [R-7] rule and the one mistake in
     * this area that looks right on screen.
     *
     * @return Collection<string, Money>
     */
    public function outstanding(): Collection
    {
        $byCurrency = [];

        foreach ($this->bookings as $booking) {
            if (! in_array($booking->status, [Booking::CONFIRMED, Booking::HELD], true)) {
                continue;
            }

            $balance = $booking->balance();

            if ($balance->minor <= 0) {
                continue;
            }

            $byCurrency[$balance->currency] = ($byCurrency[$balance->currency] ?? 0) + $balance->minor;
        }

        return collect($byCurrency)->map(
            fn (int $minor, string $currency): Money => Money::ofMinor($minor, $currency),
        );
    }

    /** @return Collection<int, Payment> */
    public function payments(): Collection
    {
        return $this->bookings
            ->flatMap(fn (Booking $booking) => $booking->payments)
            ->sortByDesc('created_at')
            ->values();
    }

    /**
     * How long since they last travelled, in months.
     *
     * Null when they never have — which is a different thing from "a long
     * time ago" and is not the same phone call.
     */
    public function monthsSinceLastJourney(): ?int
    {
        return $this->lastJourney?->date_start === null
            ? null
            : (int) $this->lastJourney->date_start->diffInMonths(now());
    }
}
