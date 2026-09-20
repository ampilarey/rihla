<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureCost;
use App\Models\Document;
use App\Models\Enquiry;
use App\Models\EnquiryNote;
use App\Models\LearningModule;
use App\Models\ModuleCompletion;
use App\Models\NusukPermit;
use App\Models\Traveller;
use App\Models\User;
use App\Support\JourneyProfit;
use App\Support\Kpi;
use App\Support\Kpis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The executive dashboard's arithmetic — §8.5 over §10.5's KPIs.
 *
 * The thing these tests exist to hold down is not the arithmetic, which is
 * easy. It is the **three states**: a measure with a figure, a measure with
 * nothing to measure, and a measure nothing records.
 *
 * Collapse the second into the first and the dashboard reports 0% for a
 * quiet fortnight. Collapse the third into the first and a last-seen
 * timestamp becomes "portal weekly-active pilgrims" in a report to a bank.
 * Both failures look like a working dashboard, which is why each one has a
 * test that plants it.
 */
class KpisTest extends TestCase
{
    use RefreshDatabase;

    private function kpi(Kpis $board, string $key): Kpi
    {
        $kpi = $board->kpis->firstWhere('key', $key);

        $this->assertNotNull($kpi, "No KPI with the key {$key} is on the board.");

        return $kpi;
    }

    /** A departure that has already flown, inside the default window. */
    private function flownDeparture(int $capacity = 40, int $daysAgo = 20): Departure
    {
        return Departure::factory()->withSeats($capacity)->create([
            'date_start' => now()->subDays($daysAgo)->startOfDay(),
            'date_end' => now()->subDays($daysAgo - 10)->startOfDay(),
        ]);
    }

    private function sell(Departure $departure, int $seats, array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => $departure->getKey(),
            'status' => Booking::CONFIRMED,
            'seats' => $seats,
            'currency' => 'MVR',
            'total_minor' => 1_000_000,
        ], $attributes));
    }

    /** @return list<Traveller> */
    private function board(Booking $booking, int $count): array
    {
        $travellers = [];

        for ($i = 0; $i < $count; $i++) {
            $traveller = Traveller::factory()->create([
                'customer_id' => $booking->customer_id,
                'full_name' => "Traveller {$i}",
            ]);

            $booking->travellers()->create([
                'traveller_id' => $traveller->getKey(),
                'occupancy' => 'quad',
                'is_lead' => $i === 0,
            ]);

            $travellers[] = $traveller;
        }

        return $travellers;
    }

    // ── The distinction the whole class exists for ───────────────────────

    /**
     * An empty window is not a zero.
     *
     * This is the defect the three states exist to prevent: a fortnight in
     * which nobody enquired, reported as a 0% conversion rate, is a board
     * meeting about a crisis that did not happen.
     */
    public function test_no_enquiries_is_nothing_to_measure_and_not_zero_per_cent(): void
    {
        $kpi = $this->kpi(Kpis::build(30), 'enquiry.to.booking');

        $this->assertSame(Kpi::NOTHING_TO_MEASURE, $kpi->state);
        $this->assertNull($kpi->figure);
        $this->assertStringNotContainsString('0%', (string) $kpi->because);
        $this->assertStringContainsString('quiet window', (string) $kpi->because);
    }

    /**
     * The four §10.5 asks for and nothing records stay named, always.
     *
     * They do not appear as zero, they do not disappear, and no proxy is
     * substituted for them.
     */
    public function test_the_four_uninstrumented_kpis_are_named_rather_than_guessed(): void
    {
        $board = Kpis::build(90);

        $this->assertSame(
            ['visit.to.enquiry', 'portal.weekly.active', 'family.portal.engagement', 'nps.after.return'],
            $board->notInstrumented()->pluck('key')->all(),
        );

        foreach ($board->notInstrumented() as $kpi) {
            $this->assertNull($kpi->figure, "{$kpi->key} must never carry a figure.");
            $this->assertNotEmpty($kpi->because, "{$kpi->key} must say what is missing.");
        }
    }

    /** The last-seen stamp is explicitly disowned as a weekly-active count. */
    public function test_the_portal_measure_explains_why_last_seen_is_not_weekly_active(): void
    {
        $kpi = $this->kpi(Kpis::build(30), 'portal.weekly.active');

        $this->assertStringContainsString('last_used_at', (string) $kpi->because);
        $this->assertStringContainsString('overwritten', (string) $kpi->because);
    }

    // ── Enquiry → booking ────────────────────────────────────────────────

    public function test_enquiry_conversion_counts_enquiries_that_became_bookings(): void
    {
        $departure = $this->flownDeparture();
        $booking = $this->sell($departure, 2);

        Enquiry::factory()->count(3)->create(['created_at' => now()->subDays(10)]);
        Enquiry::factory()->create([
            'created_at' => now()->subDays(10),
            'status' => Enquiry::WON,
            'booking_id' => $booking->getKey(),
            'customer_id' => $booking->customer_id,
        ]);

        $kpi = $this->kpi(Kpis::build(30), 'enquiry.to.booking');

        $this->assertSame('25%', $kpi->figure);
        $this->assertStringContainsString('1 of 4 enquiries became a booking', (string) $kpi->detail);
    }

    /**
     * The bias is named, not hidden.
     *
     * An enquiry that arrived yesterday has not failed to convert. Saying
     * so is what stops somebody reading a young window as a bad month.
     */
    public function test_open_enquiries_are_named_as_a_reason_the_figure_can_only_rise(): void
    {
        Enquiry::factory()->count(2)->create([
            'created_at' => now()->subDay(),
            'status' => Enquiry::NEW,
        ]);

        $kpi = $this->kpi(Kpis::build(30), 'enquiry.to.booking');

        $this->assertStringContainsString('still open', (string) $kpi->detail);
        $this->assertStringContainsString('can only go up', (string) $kpi->detail);
    }

    public function test_an_enquiry_outside_the_window_is_not_counted(): void
    {
        Enquiry::factory()->create(['created_at' => now()->subDays(200)]);

        $this->assertSame(
            Kpi::NOTHING_TO_MEASURE,
            $this->kpi(Kpis::build(30), 'enquiry.to.booking')->state,
        );

        $this->assertSame(
            Kpi::MEASURED,
            $this->kpi(Kpis::build(365), 'enquiry.to.booking')->state,
        );
    }

    // ── Time to first reply ──────────────────────────────────────────────

    public function test_response_time_is_measured_to_the_first_note_a_person_wrote(): void
    {
        $staff = User::factory()->create();

        $enquiry = Enquiry::factory()->create(['created_at' => now()->subDays(5)]);

        // A move the system recorded is not a reply.
        $enquiry->notes()->create([
            'type' => EnquiryNote::STATUS,
            'body' => 'Moved to working',
            'user_id' => $staff->getKey(),
            'created_at' => now()->subDays(5)->addMinutes(5),
        ]);

        $enquiry->notes()->create([
            'type' => EnquiryNote::NOTE,
            'body' => 'Called and sent the itinerary.',
            'user_id' => $staff->getKey(),
            'created_at' => now()->subDays(5)->addHours(3),
        ]);

        $kpi = $this->kpi(Kpis::build(30), 'enquiry.response.time');

        $this->assertSame('3 hours', $kpi->figure);
    }

    /**
     * An unanswered enquiry does not quietly flatter the median.
     *
     * It is excluded — there is no wait to measure — and the count of
     * excluded ones is on the screen, with the reason a reply might be
     * missing rather than late.
     */
    public function test_enquiries_with_no_note_are_named_rather_than_dropped(): void
    {
        $staff = User::factory()->create();

        $answered = Enquiry::factory()->create(['created_at' => now()->subDays(5)]);
        $answered->notes()->create([
            'type' => EnquiryNote::NOTE,
            'body' => 'Replied.',
            'user_id' => $staff->getKey(),
            'created_at' => now()->subDays(5)->addHour(),
        ]);

        Enquiry::factory()->count(2)->create(['created_at' => now()->subDays(5)]);

        $kpi = $this->kpi(Kpis::build(30), 'enquiry.response.time');

        $this->assertSame('1 hour', $kpi->figure);
        $this->assertStringContainsString('2 have no note at all', (string) $kpi->detail);
        $this->assertStringContainsString('phone', (string) $kpi->detail);
    }

    public function test_no_written_reply_anywhere_is_nothing_to_measure(): void
    {
        Enquiry::factory()->count(3)->create(['created_at' => now()->subDays(5)]);

        $kpi = $this->kpi(Kpis::build(30), 'enquiry.response.time');

        $this->assertSame(Kpi::NOTHING_TO_MEASURE, $kpi->state);
        $this->assertStringContainsString('WhatsApp', (string) $kpi->because);
    }

    /** The median, not the mean — one enquiry found late must not move it. */
    public function test_one_forgotten_enquiry_does_not_move_the_median(): void
    {
        $staff = User::factory()->create();

        foreach ([60, 60, 60, 60, 43_200] as $index => $minutes) {
            $enquiry = Enquiry::factory()->create(['created_at' => now()->subDays(40)]);
            $enquiry->notes()->create([
                'type' => EnquiryNote::NOTE,
                'body' => "Reply {$index}",
                'user_id' => $staff->getKey(),
                'created_at' => now()->subDays(40)->addMinutes($minutes),
            ]);
        }

        // The mean of those five waits is over two days.
        $this->assertSame('1 hour', $this->kpi(Kpis::build(90), 'enquiry.response.time')->figure);
    }

    // ── Deposit → paid in full ───────────────────────────────────────────

    /**
     * A booking that has not flown is not a failure to pay.
     *
     * Plant the opposite and the figure drops the moment a new departure
     * goes on sale, which is the season the number is read in.
     */
    public function test_a_booking_that_has_not_flown_is_not_counted_as_unpaid(): void
    {
        $upcoming = Departure::factory()->withSeats(40)->create([
            'date_start' => now()->addMonths(2)->startOfDay(),
            'date_end' => now()->addMonths(2)->addDays(12)->startOfDay(),
        ]);

        $this->sell($upcoming, 2, ['deposit_minor' => 200_000, 'paid_minor' => 200_000]);

        $this->assertSame(
            Kpi::NOTHING_TO_MEASURE,
            $this->kpi(Kpis::build(90), 'deposit.to.full')->state,
        );
    }

    public function test_deposit_conversion_counts_only_settled_journeys(): void
    {
        $departure = $this->flownDeparture();

        $this->sell($departure, 2, ['deposit_minor' => 200_000, 'paid_minor' => 1_000_000]);
        $this->sell($departure, 2, ['deposit_minor' => 200_000, 'paid_minor' => 400_000]);

        $kpi = $this->kpi(Kpis::build(90), 'deposit.to.full');

        $this->assertSame('50%', $kpi->figure);
        $this->assertStringContainsString('already flown', (string) $kpi->detail);
    }

    // ── Seats against capacity ───────────────────────────────────────────

    public function test_seats_sold_is_measured_against_the_capacity_that_flew(): void
    {
        $departure = $this->flownDeparture(capacity: 40);
        $this->sell($departure, 30);

        // A cancelled booking is not a sold seat.
        $this->sell($departure, 5, ['status' => Booking::CANCELLED]);

        $kpi = $this->kpi(Kpis::build(90), 'seats.vs.capacity');

        $this->assertSame('75%', $kpi->figure);
        $this->assertStringContainsString('30 of 40 seats', (string) $kpi->detail);
    }

    // ── Document verification ────────────────────────────────────────────

    public function test_document_cycle_time_runs_from_upload_to_verification(): void
    {
        $traveller = Traveller::factory()->create();

        $document = Document::factory()->create([
            'traveller_id' => $traveller->getKey(),
            'status' => Document::VERIFIED,
            'verified_at' => now()->subDays(2),
        ]);

        $document->versions()->create([
            'version' => 1,
            'disk' => 'local',
            'path' => 'documents/one.pdf',
            'original_filename' => 'one.pdf',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        $kpi = $this->kpi(Kpis::build(30), 'document.cycle.time');

        $this->assertSame('1 day', $kpi->figure);
    }

    /** A document nobody has looked at has no cycle time, and says so. */
    public function test_pending_documents_are_named_rather_than_counted_as_instant(): void
    {
        $traveller = Traveller::factory()->create();

        $verified = Document::factory()->create([
            'traveller_id' => $traveller->getKey(),
            'status' => Document::VERIFIED,
            'verified_at' => now()->subDays(2),
        ]);
        $verified->versions()->create([
            'version' => 1,
            'disk' => 'local',
            'path' => 'documents/one.pdf',
            'original_filename' => 'one.pdf',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        Document::factory()->count(2)->create([
            'traveller_id' => $traveller->getKey(),
            'status' => Document::PENDING,
        ]);

        $kpi = $this->kpi(Kpis::build(30), 'document.cycle.time');

        $this->assertStringContainsString('2 documents are still pending', (string) $kpi->detail);
    }

    // ── Permits before departure ─────────────────────────────────────────

    /**
     * The one that would be an accusation if it were wrong.
     *
     * No permit rows at all is an empty register, not a 0% issue rate.
     * Reporting it as 0% tells the owner the visa desk failed everybody who
     * flew, when it may simply mean nobody typed the permits in.
     */
    public function test_no_permits_recorded_is_an_empty_register_and_not_zero_per_cent(): void
    {
        $departure = $this->flownDeparture();
        $this->board($this->sell($departure, 2), 2);

        $kpi = $this->kpi(Kpis::build(90), 'permit.before.departure');

        $this->assertSame(Kpi::NOTHING_TO_MEASURE, $kpi->state);
        $this->assertNull($kpi->figure);
        $this->assertStringContainsString('empty register', (string) $kpi->because);
    }

    public function test_a_permit_issued_after_the_flight_does_not_count(): void
    {
        $departure = $this->flownDeparture(daysAgo: 20);
        $booking = $this->sell($departure, 2);
        [$first, $second] = $this->board($booking, 2);

        NusukPermit::factory()->create([
            'booking_id' => $booking->getKey(),
            'traveller_id' => $first->getKey(),
            'status' => NusukPermit::ISSUED,
            'issued_at' => now()->subDays(25),
        ]);

        NusukPermit::factory()->create([
            'booking_id' => $booking->getKey(),
            'traveller_id' => $second->getKey(),
            'status' => NusukPermit::ISSUED,
            'issued_at' => now()->subDays(5),
        ]);

        $kpi = $this->kpi(Kpis::build(90), 'permit.before.departure');

        $this->assertSame('50%', $kpi->figure);
        $this->assertSame('danger', $kpi->tone);
    }

    /**
     * A permit issued on the morning of the flight was issued in time.
     *
     * `date_start` is a date cast, so it arrives as midnight. Comparing
     * against it directly marks everybody issued on the day itself as late
     * — and the day itself is exactly when the rush happens.
     */
    public function test_a_permit_issued_on_the_day_of_the_flight_counts(): void
    {
        $departure = $this->flownDeparture(daysAgo: 20);
        $booking = $this->sell($departure, 1);
        [$traveller] = $this->board($booking, 1);

        NusukPermit::factory()->create([
            'booking_id' => $booking->getKey(),
            'traveller_id' => $traveller->getKey(),
            'status' => NusukPermit::ISSUED,
            'issued_at' => now()->subDays(20)->startOfDay()->addHours(9),
        ]);

        $this->assertSame('100%', $this->kpi(Kpis::build(90), 'permit.before.departure')->figure);
    }

    public function test_a_requested_permit_is_not_an_issued_one(): void
    {
        $departure = $this->flownDeparture(daysAgo: 20);
        $booking = $this->sell($departure, 1);
        [$traveller] = $this->board($booking, 1);

        NusukPermit::factory()->create([
            'booking_id' => $booking->getKey(),
            'traveller_id' => $traveller->getKey(),
            'status' => NusukPermit::REQUESTED,
            'issued_at' => null,
        ]);

        $this->assertSame('0%', $this->kpi(Kpis::build(90), 'permit.before.departure')->figure);
    }

    // ── Repeat and referral ──────────────────────────────────────────────

    public function test_a_returning_pilgrim_counts_as_repeat_business(): void
    {
        $returning = Customer::factory()->create();

        Booking::factory()->create([
            'customer_id' => $returning->getKey(),
            'departure_id' => $this->flownDeparture(daysAgo: 300)->getKey(),
            'status' => Booking::COMPLETED,
            'created_at' => now()->subDays(320),
        ]);

        Booking::factory()->create([
            'customer_id' => $returning->getKey(),
            'departure_id' => $this->flownDeparture()->getKey(),
            'status' => Booking::CONFIRMED,
            'created_at' => now()->subDays(10),
        ]);

        $this->sell($this->flownDeparture(), 2, ['created_at' => now()->subDays(10)]);

        $kpi = $this->kpi(Kpis::build(30), 'repeat.and.referral');

        $this->assertSame('50%', $kpi->figure);
    }

    public function test_a_referred_customer_counts_even_on_their_first_journey(): void
    {
        $referrer = Customer::factory()->create();
        $referred = Customer::factory()->create(['referred_by_customer_id' => $referrer->getKey()]);

        Booking::factory()->create([
            'customer_id' => $referred->getKey(),
            'departure_id' => $this->flownDeparture()->getKey(),
            'status' => Booking::CONFIRMED,
            'created_at' => now()->subDays(10),
        ]);

        $this->assertSame('100%', $this->kpi(Kpis::build(30), 'repeat.and.referral')->figure);
    }

    // ── Learning ─────────────────────────────────────────────────────────

    /**
     * An unpublished academy reads as "nothing to measure", not 0%.
     *
     * Every module today is a draft waiting on a scholar nobody has named.
     * A 0% completion rate would read as pilgrims ignoring the material.
     */
    public function test_no_published_module_is_nothing_to_measure(): void
    {
        LearningModule::factory()->count(3)->create();

        $kpi = $this->kpi(Kpis::build(90), 'learning.completion');

        $this->assertSame(Kpi::NOTHING_TO_MEASURE, $kpi->state);
        $this->assertStringContainsString('no reviewer has been named', (string) $kpi->because);
    }

    public function test_learning_completion_counts_published_modules_against_booked_pilgrims(): void
    {
        $module = LearningModule::factory()->published()->create();
        LearningModule::factory()->published()->create();

        $departure = $this->flownDeparture(daysAgo: 5);
        $booking = $this->sell($departure, 2);
        [$first] = $this->board($booking, 2);

        ModuleCompletion::create([
            'traveller_id' => $first->getKey(),
            'learning_module_id' => $module->getKey(),
            'read_at' => now()->subDays(6),
        ]);

        $kpi = $this->kpi(Kpis::build(30), 'learning.completion');

        // Two pilgrims, two published modules, one read: 1 of 4.
        $this->assertSame('25%', $kpi->figure);
        $this->assertStringContainsString('1 of a possible 4', (string) $kpi->detail);
    }

    // ── Margin ───────────────────────────────────────────────────────────

    public function test_margin_per_traveller_is_the_journey_margin_over_the_people_on_it(): void
    {
        $departure = $this->flownDeparture();
        $this->sell($departure, 10, ['total_minor' => 10_000_000]);

        DepartureCost::factory()->create([
            'departure_id' => $departure->getKey(),
            'amount_minor' => 6_000_000,
            'currency' => 'MVR',
            'is_per_person' => false,
            'status' => DepartureCost::COMMITTED,
        ]);

        $kpi = $this->kpi(Kpis::build(90), 'margin.per.traveller');

        $this->assertSame(Kpi::MEASURED, $kpi->state);
        $this->assertStringContainsString('4,000', (string) $kpi->figure);
        $this->assertStringContainsString('10 travellers', (string) $kpi->detail);
    }

    /**
     * The refusal to invent an exchange rate survives the trip up here.
     *
     * {@see JourneyProfit} will not total across currencies
     * without a configured rate. A dashboard that quietly averaged the
     * journeys it *could* total, without saying which it dropped, would
     * report a margin over half the business as if it were all of it.
     */
    public function test_a_journey_with_no_exchange_rate_is_left_out_and_said_so(): void
    {
        config(['finance.rates' => ['to' => 'MVR', 'as_of' => null]]);

        $mixed = $this->flownDeparture(daysAgo: 15);
        $this->sell($mixed, 5, ['total_minor' => 5_000_000]);
        DepartureCost::factory()->create([
            'departure_id' => $mixed->getKey(),
            'amount_minor' => 100_000,
            'currency' => 'USD',
            'is_per_person' => false,
            'status' => DepartureCost::COMMITTED,
        ]);

        $plain = $this->flownDeparture(daysAgo: 25);
        $this->sell($plain, 5, ['total_minor' => 5_000_000]);
        DepartureCost::factory()->create([
            'departure_id' => $plain->getKey(),
            'amount_minor' => 2_500_000,
            'currency' => 'MVR',
            'is_per_person' => false,
            'status' => DepartureCost::COMMITTED,
        ]);

        $kpi = $this->kpi(Kpis::build(90), 'margin.per.traveller');

        $this->assertStringContainsString('1 journey is left out', (string) $kpi->detail);
        $this->assertStringContainsString('exchange rate', (string) $kpi->detail);
    }

    /** A reader without profit.view gets no margin row at all. */
    public function test_the_margin_is_absent_when_the_reader_may_not_see_money(): void
    {
        $withMoney = Kpis::build(90, includeMargin: true);
        $without = Kpis::build(90, includeMargin: false);

        $this->assertNotNull($withMoney->kpis->firstWhere('key', 'margin.per.traveller'));
        $this->assertNull($without->kpis->firstWhere('key', 'margin.per.traveller'));
    }

    // ── Formatting ───────────────────────────────────────────────────────

    public function test_durations_are_written_the_way_a_person_would_say_them(): void
    {
        $this->assertSame('1 minute', Kpi::duration(1));
        $this->assertSame('45 minutes', Kpi::duration(45));
        $this->assertSame('1 hour', Kpi::duration(60));
        // Not "2 hours": rounding up overstates the wait by a third in
        // exactly the range the office argues about.
        $this->assertSame('1 hour 30 minutes', Kpi::duration(90));
        $this->assertSame('3 hours', Kpi::duration(180));
        $this->assertSame('1 day 4 hours', Kpi::duration(1440 * 1.2));
        $this->assertSame('4 days', Kpi::duration(1440 * 4));
    }

    public function test_the_median_of_an_even_set_is_the_middle_pair(): void
    {
        $this->assertSame(3.0, Kpi::median([1, 2, 4, 6]));
        $this->assertSame(4.0, Kpi::median([1, 4, 100]));
        $this->assertNull(Kpi::median([]));
    }

    public function test_a_percentage_over_nothing_is_null_and_never_zero(): void
    {
        $this->assertNull(Kpi::percentOf(0, 0));
        $this->assertSame('0%', Kpi::percentOf(0, 4));
    }

    /** An unknown window falls back rather than producing an empty span. */
    public function test_an_unrecognised_window_falls_back_to_ninety_days(): void
    {
        $this->assertSame(90, Kpis::build(7)->days);
        $this->assertSame('Last 30 days', Kpis::build(30)->windowLabel());
    }
}
