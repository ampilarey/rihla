<?php

namespace Tests\Feature;

use App\Exceptions\IllegalBookingTransition;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\Package;
use App\Models\PriceTier;
use App\Models\Traveller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The booking aggregate: its reference, the snapshot of what was sold, the
 * status machine, and the money.
 */
class BookingTest extends TestCase
{
    use RefreshDatabase;

    private function booking(array $attributes = []): Booking
    {
        return Booking::factory()->create($attributes);
    }

    // ── Reference ─────────────────────────────────────────────────────────

    public function test_a_booking_gets_a_reference(): void
    {
        $booking = $this->booking();

        $this->assertMatchesRegularExpression(
            '/^RIH-B-\d{4}-\d{4}$/',
            $booking->fresh()->reference,
        );
    }

    public function test_the_reference_carries_the_year_it_was_made(): void
    {
        $this->assertStringContainsString(
            '-'.now()->format('Y').'-',
            $this->booking()->fresh()->reference,
        );
    }

    /**
     * Derived from the primary key, not from a count, so two staff saving in
     * the same second cannot mint the same one.
     */
    public function test_references_are_unique(): void
    {
        $references = collect(range(1, 5))
            ->map(fn (): string => $this->booking()->fresh()->reference);

        $this->assertCount(5, $references->unique());
    }

    // ── The snapshot ──────────────────────────────────────────────────────

    public function test_a_booking_freezes_what_was_sold(): void
    {
        $package = Package::factory()->create(['title' => ['en' => 'Ramadan 14 Nights']]);
        $departure = Departure::factory()->for($package)->withSeats(10)->create(['airline' => 'Qatar Airways']);
        DepartureHotel::create([
            'departure_id' => $departure->getKey(),
            'city' => 'Makkah', 'name' => 'Swissôtel Al Maqam', 'distance_metres' => 150,
        ]);
        PriceTier::create([
            'departure_id' => $departure->getKey(),
            'occupancy' => 'quad', 'amount_minor' => 2_850_000,
        ]);

        $snapshot = $this->booking(['departure_id' => $departure->getKey()])->package_snapshot;

        $this->assertSame(1, $snapshot['version']);
        $this->assertSame('Ramadan 14 Nights', $snapshot['package']['title']['en']);
        $this->assertSame('Qatar Airways', $snapshot['departure']['airline']);
        $this->assertSame('Swissôtel Al Maqam', $snapshot['hotels'][0]['name']);
        $this->assertSame(2_850_000, $snapshot['price_tiers'][0]['amount_minor']);
    }

    /**
     * The whole point: editing the package next month must not change what
     * somebody bought last month.
     */
    public function test_editing_the_package_afterwards_does_not_change_the_snapshot(): void
    {
        $package = Package::factory()->create(['title' => ['en' => 'As Sold']]);
        $departure = Departure::factory()->for($package)->withSeats(10)->create();
        $booking = $this->booking(['departure_id' => $departure->getKey()]);

        $package->update(['title' => ['en' => 'Renamed Later']]);
        $departure->update(['airline' => 'A Different Airline']);

        $this->assertSame('As Sold', $booking->fresh()->package_snapshot['package']['title']['en']);
    }

    /** Both languages are kept: the confirmation may be read in one and the invoice in the other. */
    public function test_the_snapshot_keeps_every_translation(): void
    {
        $package = Package::factory()->create(['title' => ['en' => 'Ramadan', 'dv' => 'ރަމަޟާން']]);
        $departure = Departure::factory()->for($package)->withSeats(10)->create();

        $snapshot = $this->booking(['departure_id' => $departure->getKey()])->package_snapshot;

        $this->assertSame('Ramadan', $snapshot['package']['title']['en']);
        $this->assertSame('ރަމަޟާން', $snapshot['package']['title']['dv']);
    }

    // ── Status ────────────────────────────────────────────────────────────

    public function test_a_booking_starts_as_a_draft(): void
    {
        $this->assertSame(Booking::DRAFT, $this->booking()->status);
    }

    public function test_a_transition_is_recorded(): void
    {
        $staff = User::factory()->create();
        $booking = $this->booking();

        $booking->transitionTo(Booking::HELD, 'Checkout started', $staff);

        $transition = $booking->transitions()->sole();
        $this->assertSame(Booking::DRAFT, $transition->from_status);
        $this->assertSame(Booking::HELD, $transition->to_status);
        $this->assertSame('Checkout started', $transition->reason);
        $this->assertSame($staff->getKey(), $transition->user_id);
    }

    /** A hold lapsing at 02:00 has no author, and must not be given one. */
    public function test_a_system_transition_has_no_actor(): void
    {
        $booking = $this->booking();
        $booking->transitionTo(Booking::HELD);
        $booking->transitionTo(Booking::EXPIRED, 'The seat hold lapsed before payment.');

        $this->assertNull($booking->transitions()->latest('id')->first()->user_id);
    }

    public function test_an_illegal_transition_throws(): void
    {
        $booking = $this->booking();

        $this->expectException(IllegalBookingTransition::class);

        $booking->transitionTo(Booking::COMPLETED);
    }

    public function test_a_cancelled_booking_is_final(): void
    {
        $booking = $this->booking();
        $booking->transitionTo(Booking::CANCELLED, 'Changed their mind');

        $this->expectExceptionMessage('it is a final status');

        $booking->transitionTo(Booking::HELD);
    }

    /**
     * Reviving a lapsed booking would re-take seats somebody else may hold,
     * at a price that may have changed. Coming back means a new booking.
     */
    public function test_an_expired_booking_cannot_be_revived(): void
    {
        $booking = $this->booking();
        $booking->transitionTo(Booking::HELD);
        $booking->transitionTo(Booking::EXPIRED);

        $this->expectException(IllegalBookingTransition::class);

        $booking->transitionTo(Booking::HELD);
    }

    public function test_confirming_stamps_the_time(): void
    {
        $booking = $this->booking();
        $booking->transitionTo(Booking::HELD);
        $booking->transitionTo(Booking::CONFIRMED);

        $this->assertNotNull($booking->fresh()->confirmed_at);
    }

    public function test_cancelling_records_the_reason(): void
    {
        $booking = $this->booking();
        $booking->transitionTo(Booking::CANCELLED, 'Passport expired');

        $booking->refresh();
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame('Passport expired', $booking->cancellation_reason);
    }

    /** Every status must be reachable, or it is a state nothing can produce. */
    public function test_the_transition_table_covers_every_status(): void
    {
        $this->assertSame(
            Booking::STATUSES,
            array_keys(Booking::TRANSITIONS),
        );
    }

    // ── Money ─────────────────────────────────────────────────────────────

    public function test_the_total_is_the_sum_of_the_lines(): void
    {
        $booking = $this->booking();

        $booking->lines()->create([
            'type' => BookingLine::SEAT, 'description' => 'Quad, adult', 'amount_minor' => 2_850_000,
        ]);
        $booking->lines()->create([
            'type' => BookingLine::SEAT, 'description' => 'Quad, adult', 'amount_minor' => 2_850_000,
        ]);

        $booking->recalculateTotal();

        $this->assertSame(5_700_000, $booking->fresh()->total_minor);
        $this->assertSame('MVR 57,000', $booking->fresh()->total()->format());
    }

    /**
     * A discount is a negative line. The columns are signed for this reason;
     * an unsigned one would turn −250 rufiyaa into a number with nineteen
     * digits and a total nobody could explain.
     */
    public function test_a_discount_reduces_the_total(): void
    {
        $booking = $this->booking();

        $booking->lines()->create([
            'type' => BookingLine::SEAT, 'description' => 'Quad, adult', 'amount_minor' => 2_850_000,
        ]);
        $booking->lines()->create([
            'type' => BookingLine::DISCOUNT, 'description' => 'Family of four', 'amount_minor' => -25_000,
        ]);

        $booking->recalculateTotal();

        $this->assertSame(2_825_000, $booking->fresh()->total_minor);
    }

    public function test_the_balance_is_the_total_less_what_was_paid(): void
    {
        $booking = $this->booking();
        $booking->forceFill(['total_minor' => 2_850_000, 'paid_minor' => 1_000_000])->save();

        $this->assertSame('MVR 18,500', $booking->balance()->format());
    }

    public function test_a_booking_carries_one_currency(): void
    {
        $this->assertSame('MVR', $this->booking()->currency);
    }

    // ── Travellers ────────────────────────────────────────────────────────

    public function test_the_lead_traveller_is_the_one_marked_as_such(): void
    {
        $customer = Customer::factory()->create();
        $booking = $this->booking(['customer_id' => $customer->getKey(), 'seats' => 2]);

        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create(['full_name' => 'Not This One'])->getKey(),
            'occupancy' => 'quad',
        ]);
        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create(['full_name' => 'The Payer'])->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        $this->assertSame('The Payer', $booking->load('travellers.traveller')->leadTraveller()->traveller->full_name);
    }

    public function test_the_same_person_cannot_be_on_a_booking_twice(): void
    {
        $traveller = Traveller::factory()->create();
        $booking = $this->booking();

        $booking->travellers()->create(['traveller_id' => $traveller->getKey(), 'occupancy' => 'quad']);

        $this->expectException(QueryException::class);

        $booking->travellers()->create(['traveller_id' => $traveller->getKey(), 'occupancy' => 'quad']);
    }

    /** Age at travel, not today: a fourteen-year-old who turns fifteen in the air is a different booking. */
    public function test_a_travellers_age_is_measured_at_the_departure_date(): void
    {
        $traveller = Traveller::factory()->create(['date_of_birth' => now()->subYears(12)->subMonths(11)]);

        $this->assertSame(12, $traveller->ageOn(now()));
        $this->assertSame(13, $traveller->ageOn(now()->addMonths(2)));
    }

    public function test_a_traveller_without_a_date_of_birth_has_no_age(): void
    {
        $this->assertNull(Traveller::factory()->create(['date_of_birth' => null])->ageOn(now()));
    }

    // ── Referential care ──────────────────────────────────────────────────

    /**
     * A booking is a financial record. Deleting the customer must fail
     * loudly rather than quietly taking their bookings with it.
     */
    public function test_a_customer_with_bookings_cannot_be_deleted(): void
    {
        $customer = Customer::factory()->create();
        $this->booking(['customer_id' => $customer->getKey()]);

        $this->expectException(QueryException::class);

        $customer->delete();
    }

    public function test_a_departure_that_has_been_sold_cannot_be_deleted(): void
    {
        $departure = Departure::factory()->withSeats(10)->create();
        $this->booking(['departure_id' => $departure->getKey()]);

        $this->expectException(QueryException::class);

        $departure->delete();
    }
}
