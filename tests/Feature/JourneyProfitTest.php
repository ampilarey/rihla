<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureCost;
use App\Support\JourneyProfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-journey profitability — §8.4, "the report that changes pricing
 * decisions".
 *
 * Three things these hold down:
 *
 * 1. **Per person and per departure are different costs.** Without the
 *    distinction the margin is wrong at every party size except the one
 *    somebody happened to have in mind, and wrong in a direction that
 *    flatters a small group.
 * 2. **Nothing is added across currencies without a rate somebody set.**
 *    Hotels bill in SAR and the office collects MVR; a made-up rate here
 *    is a number a season gets priced on.
 * 3. **Estimates and paid costs are different questions**, and the report
 *    says which it answered.
 */
class JourneyProfitTest extends TestCase
{
    use RefreshDatabase;

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(30)->create([
            'date_start' => now()->subMonth()->startOfDay(),
            'date_end' => now()->subMonth()->addDays(14)->startOfDay(),
        ]);
    }

    private function sell(Departure $departure, int $seats, int $totalMinor, string $currency = 'MVR'): Booking
    {
        return Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => $departure->getKey(),
            'status' => Booking::CONFIRMED,
            'seats' => $seats,
            'currency' => $currency,
            'total_minor' => $totalMinor,
        ]);
    }

    // ── Per person versus per departure ──────────────────────────────────

    public function test_a_fixed_cost_does_not_multiply_by_the_party(): void
    {
        $departure = $this->departure();
        $this->sell($departure, seats: 10, totalMinor: 10_000_000);

        DepartureCost::factory()->create([
            'departure_id' => $departure->getKey(),
            'amount_minor' => 2_000_000,
            'is_per_person' => false,
        ]);

        $profit = JourneyProfit::build($departure);

        $this->assertSame(10, $profit->travellers);
        $this->assertSame(2_000_000, $profit->costs->get('MVR')->minor);
        $this->assertSame(8_000_000, $profit->marginByCurrency()->get('MVR')->minor);
    }

    public function test_a_per_person_cost_multiplies_by_the_party(): void
    {
        $departure = $this->departure();
        $this->sell($departure, seats: 10, totalMinor: 10_000_000);

        DepartureCost::factory()->perPerson()->create([
            'departure_id' => $departure->getKey(),
            'amount_minor' => 200_000,
        ]);

        $profit = JourneyProfit::build($departure);

        $this->assertSame(2_000_000, $profit->costs->get('MVR')->minor);
    }

    /**
     * The whole reason the column exists.
     *
     * The same two costs against eighteen travellers and thirty give
     * different answers, and a report that could not tell them apart would
     * flatter the smaller group.
     */
    public function test_the_two_kinds_of_cost_behave_differently_as_the_party_grows(): void
    {
        $small = $this->departure();
        $this->sell($small, seats: 5, totalMinor: 5_000_000);

        $large = $this->departure();
        $this->sell($large, seats: 20, totalMinor: 20_000_000);

        foreach ([$small, $large] as $departure) {
            DepartureCost::factory()->create([
                'departure_id' => $departure->getKey(),
                'amount_minor' => 1_000_000,
                'is_per_person' => false,
            ]);

            DepartureCost::factory()->perPerson()->create([
                'departure_id' => $departure->getKey(),
                'amount_minor' => 100_000,
            ]);
        }

        // 1,000,000 + 5 × 100,000 = 1,500,000
        $this->assertSame(1_500_000, JourneyProfit::build($small)->costs->get('MVR')->minor);
        // 1,000,000 + 20 × 100,000 = 3,000,000
        $this->assertSame(3_000_000, JourneyProfit::build($large)->costs->get('MVR')->minor);
    }

    // ── Which costs count ────────────────────────────────────────────────

    public function test_the_three_statuses_give_three_answers(): void
    {
        $departure = $this->departure();
        $this->sell($departure, seats: 10, totalMinor: 10_000_000);

        foreach ([DepartureCost::ESTIMATED, DepartureCost::COMMITTED, DepartureCost::PAID] as $status) {
            DepartureCost::factory()->withStatus($status)->create([
                'departure_id' => $departure->getKey(),
                'amount_minor' => 1_000_000,
            ]);
        }

        $this->assertSame(
            3_000_000,
            JourneyProfit::build($departure, DepartureCost::ESTIMATED)->costs->get('MVR')->minor,
        );
        $this->assertSame(
            2_000_000,
            JourneyProfit::build($departure, DepartureCost::COMMITTED)->costs->get('MVR')->minor,
        );
        $this->assertSame(
            1_000_000,
            JourneyProfit::build($departure, DepartureCost::PAID)->costs->get('MVR')->minor,
        );
    }

    public function test_the_report_says_which_costs_it_counted(): void
    {
        $departure = $this->departure();

        $this->assertStringContainsString(
            'estimates',
            JourneyProfit::build($departure, DepartureCost::ESTIMATED)->countingLabel(),
        );
        $this->assertStringContainsString(
            'paid',
            JourneyProfit::build($departure, DepartureCost::PAID)->countingLabel(),
        );
    }

    // ── Revenue is money actually owed ───────────────────────────────────

    public function test_a_draft_or_cancelled_booking_is_not_revenue(): void
    {
        $departure = $this->departure();
        $this->sell($departure, seats: 5, totalMinor: 5_000_000);

        Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => $departure->getKey(),
            'status' => Booking::DRAFT,
            'seats' => 5,
            'currency' => 'MVR',
            'total_minor' => 5_000_000,
        ]);

        $profit = JourneyProfit::build($departure);

        $this->assertSame(5, $profit->travellers);
        $this->assertSame(5_000_000, $profit->revenue->get('MVR')->minor);
    }

    // ── Currencies are never added without a rate ────────────────────────

    /**
     * Hotels bill in SAR, the office collects MVR. A made-up rate here is
     * a number somebody prices a season on.
     */
    public function test_without_a_rate_there_is_no_single_figure_and_it_says_why(): void
    {
        config(['finance.rates' => ['to' => 'MVR']]);

        $departure = $this->departure();
        $this->sell($departure, seats: 10, totalMinor: 10_000_000);

        DepartureCost::factory()->inCurrency('SAR', 100_000)->create([
            'departure_id' => $departure->getKey(),
        ]);

        $profit = JourneyProfit::build($departure);

        $this->assertNull($profit->margin());
        $this->assertNull($profit->marginPerTraveller());
        $this->assertStringContainsString('SAR', (string) $profit->whyNoSingleFigure());
        $this->assertStringContainsString('no exchange rate has been set', (string) $profit->whyNoSingleFigure());

        // And the per-currency figures are still there, which is the point.
        $this->assertSame(10_000_000, $profit->marginByCurrency()->get('MVR')->minor);
        $this->assertSame(-100_000, $profit->marginByCurrency()->get('SAR')->minor);
    }

    public function test_with_a_rate_the_journey_totals_and_names_the_rate(): void
    {
        // 1 SAR = 4.11 MVR, stated by the operator rather than invented.
        config(['finance.rates' => ['to' => 'MVR', 'SAR' => 411, 'as_of' => '2026-09-01']]);

        $departure = $this->departure();
        $this->sell($departure, seats: 10, totalMinor: 10_000_000);

        DepartureCost::factory()->inCurrency('SAR', 100_000)->create([
            'departure_id' => $departure->getKey(),
        ]);

        $profit = JourneyProfit::build($departure);

        $this->assertNull($profit->whyNoSingleFigure());
        // 10,000,000 MVR laari − (100,000 SAR halalas × 4.11) = 9,589,000
        $this->assertSame(9_589_000, $profit->margin()->minor);
        $this->assertSame('MVR', $profit->margin()->currency);
        $this->assertSame(958_900, $profit->marginPerTraveller()->minor);
        $this->assertStringContainsString('2026-09-01', (string) $profit->rateNote());
    }

    /** A rate with no date is a rate nobody knows the age of. */
    public function test_a_rate_with_no_date_says_so(): void
    {
        config(['finance.rates' => ['to' => 'MVR', 'SAR' => 411]]);

        $departure = $this->departure();
        $this->sell($departure, seats: 2, totalMinor: 2_000_000);
        DepartureCost::factory()->inCurrency('SAR', 10_000)->create(['departure_id' => $departure->getKey()]);

        $this->assertStringContainsString(
            'nobody has recorded when they were last checked',
            (string) JourneyProfit::build($departure)->rateNote(),
        );
    }

    public function test_one_currency_throughout_needs_no_rate_at_all(): void
    {
        config(['finance.rates' => ['to' => 'MVR']]);

        $departure = $this->departure();
        $this->sell($departure, seats: 4, totalMinor: 4_000_000);
        DepartureCost::factory()->create(['departure_id' => $departure->getKey(), 'amount_minor' => 1_000_000]);

        $profit = JourneyProfit::build($departure);

        $this->assertNull($profit->whyNoSingleFigure());
        $this->assertSame(3_000_000, $profit->margin()->minor);
        // No rate note: nothing was converted.
        $this->assertNull($profit->rateNote());
    }

    // ── The shape of the answer ──────────────────────────────────────────

    public function test_a_loss_is_reported_as_a_loss(): void
    {
        $departure = $this->departure();
        $this->sell($departure, seats: 2, totalMinor: 1_000_000);

        DepartureCost::factory()->create([
            'departure_id' => $departure->getKey(),
            'amount_minor' => 3_000_000,
        ]);

        $this->assertSame(-2_000_000, JourneyProfit::build($departure)->margin()->minor);
    }

    public function test_costs_are_broken_down_by_what_they_were_for(): void
    {
        $departure = $this->departure();
        $this->sell($departure, seats: 2, totalMinor: 5_000_000);

        DepartureCost::factory()->create([
            'departure_id' => $departure->getKey(),
            'category' => DepartureCost::HOTEL,
            'amount_minor' => 1_000_000,
        ]);
        DepartureCost::factory()->create([
            'departure_id' => $departure->getKey(),
            'category' => DepartureCost::FLIGHT,
            'amount_minor' => 2_000_000,
        ]);

        $byCategory = JourneyProfit::build($departure)->costsByCategory;

        $this->assertSame(1_000_000, $byCategory->get(DepartureCost::HOTEL)->get('MVR')->minor);
        $this->assertSame(2_000_000, $byCategory->get(DepartureCost::FLIGHT)->get('MVR')->minor);
    }

    /**
     * A departure with revenue and no costs shows the whole take as
     * margin, which it is not. The page says so; this proves the numbers
     * behind that sentence.
     */
    public function test_a_journey_with_no_costs_recorded_shows_all_revenue_as_margin(): void
    {
        $departure = $this->departure();
        $this->sell($departure, seats: 3, totalMinor: 3_000_000);

        $profit = JourneyProfit::build($departure);

        $this->assertTrue($profit->costsByCategory->isEmpty());
        $this->assertSame(3_000_000, $profit->margin()->minor);
    }

    public function test_every_category_and_status_has_a_sentence(): void
    {
        foreach (DepartureCost::CATEGORIES as $category) {
            $this->assertNotSame('Unknown', (new DepartureCost(['category' => $category]))->categoryLabel());
        }

        foreach (DepartureCost::STATUSES as $status) {
            $this->assertNotSame('Unknown', (new DepartureCost(['status' => $status]))->statusLabel());
        }
    }

    public function test_no_cost_is_seeded(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $this->assertSame(0, DepartureCost::count());
    }
}
