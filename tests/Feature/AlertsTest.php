<?php

namespace Tests\Feature;

use App\Filament\Pages\NeedsAttention;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Enquiry;
use App\Models\KnowledgeArticle;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Access;
use App\Support\Alert;
use App\Support\Alerts;
use App\Support\Referrals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smart alerts — §8.5.
 *
 * Two failures would each make this screen worse than nothing, and each
 * has a test that plants it:
 *
 * 1. **Raising what another screen already watches.** Two copies of the
 *    same number in an office leave both less trusted than one, so the
 *    conditions here are the ones with no screen on them.
 * 2. **Showing somebody an alert they cannot act on.** A tour leader told
 *    that MVR 84,000 is waiting to be reconciled has learned something
 *    about the business and can do nothing with it.
 */
class AlertsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function alert(string $key): ?Alert
    {
        return Alerts::all()->firstWhere('key', $key);
    }

    private function departure(int $daysFromNow, int $capacity = 40): Departure
    {
        return Departure::factory()->withSeats($capacity)->create([
            'date_start' => now()->addDays($daysFromNow)->startOfDay(),
            'date_end' => now()->addDays($daysFromNow + 10)->startOfDay(),
        ]);
    }

    private function sell(Departure $departure, int $seats, ?\DateTimeInterface $at = null): Booking
    {
        return Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => $departure->getKey(),
            'status' => Booking::CONFIRMED,
            'seats' => $seats,
            'total_minor' => 1_000_000,
            'created_at' => $at ?? now(),
        ]);
    }

    /** Three past journeys of one package that each half-sold 60 days out. */
    private function history(Package $package, int $early = 20, int $final = 40): void
    {
        foreach ([100, 160, 220] as $ago) {
            $departure = Departure::factory()->withSeats(40)->create([
                'package_id' => $package->getKey(),
                'date_start' => now()->subDays($ago)->startOfDay(),
                'date_end' => now()->subDays($ago - 10)->startOfDay(),
            ]);

            $this->sell($departure, $early, now()->subDays($ago + 60));
            $this->sell($departure, $final - $early, now()->subDays($ago + 1));
        }
    }

    // ── Nothing wrong is a real answer ───────────────────────────────────

    public function test_a_quiet_office_raises_nothing(): void
    {
        $this->assertTrue(Alerts::all()->isEmpty());
    }

    public function test_the_page_says_so_rather_than_looking_broken(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->get(NeedsAttention::getUrl())
            ->assertSuccessful()
            ->assertSee('Nothing is raising its hand');
    }

    // ── Money waiting ────────────────────────────────────────────────────

    public function test_money_nobody_has_looked_at_is_urgent(): void
    {
        $booking = $this->sell($this->departure(60), 2);

        Payment::factory()->count(2)->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'status' => Payment::AWAITING_REVIEW,
            'currency' => 'MVR',
            'amount_minor' => 500_000,
            'created_at' => now()->subDays(6),
        ]);

        $alert = $this->alert('payment.unreviewed');

        $this->assertNotNull($alert);
        $this->assertTrue($alert->isUrgent());
        $this->assertSame(2, $alert->count);
        $this->assertStringContainsString('MVR 10,000', $alert->detail);
        $this->assertStringContainsString('assumes the money is lost', $alert->detail);
    }

    /** A slip uploaded this morning is not an oversight. */
    public function test_a_payment_waiting_a_day_is_not_an_alert(): void
    {
        $booking = $this->sell($this->departure(60), 2);

        Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'status' => Payment::AWAITING_REVIEW,
            'created_at' => now()->subDay(),
        ]);

        $this->assertNull($this->alert('payment.unreviewed'));
    }

    /**
     * Currencies are never summed, here as everywhere — [R-7].
     */
    public function test_two_currencies_are_reported_apart(): void
    {
        $booking = $this->sell($this->departure(60), 2);

        foreach ([['MVR', 300_000], ['USD', 20_000]] as [$currency, $minor]) {
            Payment::factory()->create([
                'payable_type' => Booking::class,
                'payable_id' => $booking->getKey(),
                'status' => Payment::AWAITING_REVIEW,
                'currency' => $currency,
                'amount_minor' => $minor,
                'created_at' => now()->subDays(5),
            ]);
        }

        $detail = (string) $this->alert('payment.unreviewed')?->detail;

        $this->assertStringContainsString('MVR 3,000', $detail);
        $this->assertStringContainsString('USD 200', $detail);
        $this->assertStringContainsString(' and ', $detail);
    }

    // ── Quotations lapsing ───────────────────────────────────────────────

    public function test_a_quotation_about_to_lapse_with_no_answer_is_raised(): void
    {
        Quotation::factory()->create([
            'enquiry_id' => Enquiry::factory()->create()->getKey(),
            'status' => Quotation::SENT,
            'booking_id' => null,
            'valid_until' => now()->addDays(2)->toDateString(),
        ]);

        $alert = $this->alert('quotation.expiring');

        $this->assertNotNull($alert);
        $this->assertFalse($alert->isUrgent());
        $this->assertStringContainsString('decided somewhere else', $alert->detail);
    }

    public function test_a_quotation_that_turned_into_a_booking_is_not_chased(): void
    {
        $booking = $this->sell($this->departure(60), 2);

        Quotation::factory()->create([
            'enquiry_id' => Enquiry::factory()->create()->getKey(),
            'status' => Quotation::SENT,
            'booking_id' => $booking->getKey(),
            'valid_until' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertNull($this->alert('quotation.expiring'));
    }

    // ── The forecast conditions ──────────────────────────────────────────

    public function test_a_departure_off_its_pace_is_raised_while_there_is_time(): void
    {
        $package = Package::factory()->create();
        $this->history($package);

        $selling = Departure::factory()->withSeats(60)->create([
            'package_id' => $package->getKey(),
            'date_start' => now()->addDays(60)->startOfDay(),
            'date_end' => now()->addDays(70)->startOfDay(),
        ]);
        $this->sell($selling, 10);

        $alert = $this->alert('forecast.short');

        $this->assertNotNull($alert);
        $this->assertStringContainsString('short by 40 seats', $alert->detail);
        $this->assertSame('kpi.view', $alert->permission);
    }

    /**
     * Three weeks out it is a fact, not an alert.
     *
     * Raising it then teaches people to ignore the one that arrived in
     * time, which is the only one worth having.
     */
    public function test_a_departure_too_close_to_fix_is_not_raised(): void
    {
        $package = Package::factory()->create();

        foreach ([100, 160, 220] as $ago) {
            $departure = Departure::factory()->withSeats(40)->create([
                'package_id' => $package->getKey(),
                'date_start' => now()->subDays($ago)->startOfDay(),
                'date_end' => now()->subDays($ago - 10)->startOfDay(),
            ]);

            $this->sell($departure, 20, now()->subDays($ago + 14));
            $this->sell($departure, 20, now()->subDays($ago + 1));
        }

        $selling = Departure::factory()->withSeats(60)->create([
            'package_id' => $package->getKey(),
            'date_start' => now()->addDays(14)->startOfDay(),
            'date_end' => now()->addDays(24)->startOfDay(),
        ]);
        $this->sell($selling, 10);

        $this->assertNull($this->alert('forecast.short'));
    }

    public function test_a_departure_selling_past_its_capacity_is_raised(): void
    {
        $package = Package::factory()->create();
        $this->history($package);

        $selling = Departure::factory()->withSeats(20)->create([
            'package_id' => $package->getKey(),
            'date_start' => now()->addDays(60)->startOfDay(),
            'date_end' => now()->addDays(70)->startOfDay(),
        ]);
        $this->sell($selling, 20);

        $alert = $this->alert('forecast.oversubscribed');

        $this->assertNotNull($alert);
        $this->assertStringContainsString('while the airline still has them', $alert->detail);
    }

    // ── Scholar gone quiet ───────────────────────────────────────────────

    public function test_content_waiting_a_month_on_a_scholar_is_raised(): void
    {
        KnowledgeArticle::factory()->create([
            'status' => KnowledgeArticle::IN_REVIEW,
            'updated_at' => now()->subDays(40),
        ]);

        $alert = $this->alert('review.stale');

        $this->assertNotNull($alert);
        $this->assertStringContainsString('a quiet reviewer is a stopped queue', $alert->detail);
    }

    public function test_content_waiting_a_few_days_is_not_raised(): void
    {
        KnowledgeArticle::factory()->create([
            'status' => KnowledgeArticle::IN_REVIEW,
            'updated_at' => now()->subDays(3),
        ]);

        $this->assertNull($this->alert('review.stale'));
    }

    // ── Who sees what ────────────────────────────────────────────────────

    /**
     * The gating that stops this becoming a disclosure.
     *
     * A tour leader has no business reading the office's outstanding
     * money, and could do nothing about it if they did.
     */
    public function test_a_tour_leader_is_not_shown_the_money_alert(): void
    {
        $booking = $this->sell($this->departure(60), 2);

        Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'status' => Payment::AWAITING_REVIEW,
            'currency' => 'MVR',
            'amount_minor' => 8_400_000,
            'created_at' => now()->subDays(5),
        ]);

        $leader = $this->staff(Access::TOUR_LEADER);

        $this->assertNotNull($this->alert('payment.unreviewed'));
        $this->assertNull(Alerts::for($leader)->firstWhere('key', 'payment.unreviewed'));

        $this->actingAs($leader)
            ->get(NeedsAttention::getUrl())
            ->assertSuccessful()
            ->assertDontSee('MVR 84,000');
    }

    public function test_finance_is_shown_the_money_alert(): void
    {
        $booking = $this->sell($this->departure(60), 2);

        Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'status' => Payment::AWAITING_REVIEW,
            'currency' => 'MVR',
            'amount_minor' => 8_400_000,
            'created_at' => now()->subDays(5),
        ]);

        $this->actingAs($this->staff(Access::FINANCE))
            ->get(NeedsAttention::getUrl())
            ->assertSuccessful()
            ->assertSee('MVR 84,000');
    }

    /**
     * An empty list because everything was withheld is a different empty
     * list from a quiet office, and it must not claim to be the other one.
     *
     * Caught by reading the rendered page: a tour leader saw "None of
     * those is true right now" printed directly above "5 alerts are
     * hidden" — two sentences contradicting each other on one screen, with
     * every assertion still passing.
     */
    public function test_an_all_withheld_list_does_not_claim_the_office_is_quiet(): void
    {
        $booking = $this->sell($this->departure(60), 2);

        Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'status' => Payment::AWAITING_REVIEW,
            'created_at' => now()->subDays(5),
        ]);

        $leader = $this->staff(Access::TOUR_LEADER);

        $this->assertTrue(Alerts::for($leader)->isEmpty());

        $this->actingAs($leader)
            ->get(NeedsAttention::getUrl())
            ->assertSuccessful()
            ->assertSee('Nothing here is yours to act on')
            ->assertSee('1 alert is open, and none of them is yours')
            ->assertDontSee('None of those is true right now');
    }

    /** With a list to be shorter than, the shortfall is named beside it. */
    public function test_a_shortened_list_names_what_it_is_missing(): void
    {
        $booking = $this->sell($this->departure(60), 2);

        Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'status' => Payment::AWAITING_REVIEW,
            'created_at' => now()->subDays(5),
        ]);

        Quotation::factory()->create([
            'enquiry_id' => Enquiry::factory()->create()->getKey(),
            'status' => Quotation::SENT,
            'booking_id' => null,
            'valid_until' => now()->addDays(2)->toDateString(),
        ]);

        // Booking staff can chase a quotation and cannot reconcile money.
        $this->actingAs($this->staff(Access::BOOKING_STAFF))
            ->get(NeedsAttention::getUrl())
            ->assertSuccessful()
            ->assertSee('quotation expires within 5 days')
            ->assertSee('Not shown to your role')
            ->assertSee('1 further alert is hidden');
    }

    // ── What it deliberately leaves alone ────────────────────────────────

    /**
     * An adrift enquiry belongs to Today, and appears only there.
     *
     * Two copies of the same number in one office leave both less trusted
     * than one, and this is the copy that would be stale.
     */
    public function test_an_adrift_enquiry_is_left_to_the_today_page(): void
    {
        Enquiry::factory()->create([
            'status' => Enquiry::WORKING,
            'next_action_at' => now()->subWeeks(3)->toDateString(),
            'created_at' => now()->subWeeks(4),
        ]);

        $this->assertTrue(
            Alerts::all()->every(fn (Alert $alert): bool => $alert->key !== 'enquiry.adrift'),
        );
        $this->assertTrue(Alerts::all()->isEmpty());
    }

    /** And the page says which screens own what, so nobody goes looking here. */
    public function test_the_page_names_what_it_leaves_to_other_screens(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->get(NeedsAttention::getUrl())
            ->assertSee('Adrift enquiries and follow-ups due are on Today', false)
            ->assertSee('chasing list');
    }

    // ── Ordering ─────────────────────────────────────────────────────────

    public function test_urgent_alerts_come_first(): void
    {
        $booking = $this->sell($this->departure(60), 2);

        Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'status' => Payment::AWAITING_REVIEW,
            'created_at' => now()->subDays(5),
        ]);

        Quotation::factory()->create([
            'enquiry_id' => Enquiry::factory()->create()->getKey(),
            'status' => Quotation::SENT,
            'booking_id' => null,
            'valid_until' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertSame('payment.unreviewed', Alerts::all()->first()?->key);
    }

    /**
     * Within a severity, the bigger alert comes first.
     *
     * This pins the intended order. It does **not** prove the sort is
     * implemented correctly, and it is worth being plain about that: the
     * ordering was originally written `sortBy([fn, fn])`, where Laravel
     * calls each callable as a two-argument *comparator*. A one-argument
     * accessor then returns 0 or 1 and never -1, which is not a consistent
     * comparator, and `uasort` on one of those is undefined behaviour.
     *
     * On this data the undefined behaviour happens to land on the right
     * answer, so no arrangement of alerts available here tells the two
     * implementations apart. On {@see Referrals} it lands on
     * the wrong one, and `ReferralsTest` catches it there. Both were changed
     * to two stable passes for the same reason; only one can be held down
     * by a test.
     */
    public function test_within_a_severity_the_larger_alert_comes_first(): void
    {
        // Three "this week" alerts: 3 lapsing quotations, 2 stale reviews,
        // 1 departure off its pace.
        Quotation::factory()->count(3)->create([
            'enquiry_id' => Enquiry::factory()->create()->getKey(),
            'status' => Quotation::SENT,
            'booking_id' => null,
            'valid_until' => now()->addDays(2)->toDateString(),
        ]);

        KnowledgeArticle::factory()->count(2)->create([
            'status' => KnowledgeArticle::IN_REVIEW,
            'updated_at' => now()->subDays(40),
        ]);

        $package = Package::factory()->create();
        $this->history($package);

        $selling = Departure::factory()->withSeats(60)->create([
            'package_id' => $package->getKey(),
            'date_start' => now()->addDays(60)->startOfDay(),
            'date_end' => now()->addDays(70)->startOfDay(),
        ]);
        $this->sell($selling, 10);

        $this->assertSame(
            ['quotation.expiring', 'review.stale', 'forecast.short'],
            Alerts::all()->pluck('key')->all(),
        );
    }
}
