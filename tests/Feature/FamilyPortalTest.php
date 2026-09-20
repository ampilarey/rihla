<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\FamilyAccess;
use App\Models\RollCall;
use App\Models\RollCallMark;
use App\Models\Traveller;
use App\Services\Family\Doorkeeper;
use App\Services\Portal\Gatekeeper;
use App\Support\JourneyProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The Family Portal — §6.2.
 *
 * "…with privacy controls the pilgrim owns (no individual live location
 * unless explicitly enabled; group-level status only by default)."
 *
 * Every test here is that sentence. The dangerous failures are all
 * *disclosures*: a family link that shows one person's business without
 * being told to, that keeps working after it is turned off, or that reaches
 * a pilgrim page. A green suite that does not test those proves nothing.
 */
class FamilyPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults(['locale' => 'en']);
    }

    /** @return array{0: Booking, 1: Collection<int, Traveller>} */
    private function booking(int $people = 2): array
    {
        $departure = Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subDays(3),
            'date_end' => now()->addDays(11),
        ]);

        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => $people,
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        $travellers = collect();

        for ($i = 0; $i < $people; $i++) {
            $traveller = Traveller::factory()->for($customer)->create(['full_name' => "Traveller {$i}"]);

            $booking->travellers()->create([
                'traveller_id' => $traveller->getKey(),
                'occupancy' => 'quad',
                'is_lead' => $i === 0,
            ]);

            $travellers->push($traveller);
        }

        return [$booking->fresh(), $travellers];
    }

    private function enter(Booking $booking, bool $sharesAttendance = false): FamilyAccess
    {
        $token = app(Doorkeeper::class)->issue($booking, 'Mum', $sharesAttendance);

        $this->get(route('family.enter', ['token' => $token]))->assertRedirect(route('family.home'));

        return FamilyAccess::where('booking_id', $booking->getKey())->latest('id')->sole();
    }

    // ── Getting in ───────────────────────────────────────────────────────

    public function test_a_link_opens_the_page(): void
    {
        [$booking] = $this->booking();
        $this->enter($booking);

        $this->get(route('family.home'))
            ->assertOk()
            ->assertSee($booking->departure->package->title);
    }

    public function test_without_a_link_there_is_nothing(): void
    {
        $this->get(route('family.home'))->assertRedirect(route('family.locked'));
    }

    public function test_a_made_up_token_says_so_rather_than_crashing(): void
    {
        $this->get(route('family.enter', ['token' => 'not-a-real-token']))
            ->assertRedirect(route('family.locked'));

        $this->get(route('family.locked'))->assertOk()->assertSee('does not work');
    }

    /**
     * The plaintext exists once. A row that could show it again is one that
     * could be made to show it to somebody else.
     */
    public function test_only_the_hash_is_stored(): void
    {
        [$booking] = $this->booking();
        $token = app(Doorkeeper::class)->issue($booking);

        $access = FamilyAccess::sole();

        $this->assertNotSame($token, $access->token_hash);
        $this->assertSame(hash('sha256', $token), $access->token_hash);
    }

    // ── The control the pilgrim owns ─────────────────────────────────────

    /**
     * The default, and the whole promise of §6.2: group-level only.
     *
     * A family that is handed a link without the pilgrim ticking anything
     * learns where the group is and what the office announced — and nothing
     * about any individual.
     */
    public function test_by_default_a_family_sees_nothing_about_an_individual(): void
    {
        [$booking, $travellers] = $this->booking();

        $rollCall = RollCall::create([
            'departure_id' => $booking->departure_id,
            'moment' => 'Boarding at Velana',
            'taken_at' => now(),
        ]);

        RollCallMark::create([
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::PRESENT,
        ]);

        $this->enter($booking, sharesAttendance: false);

        $this->get(route('family.home'))
            ->assertOk()
            ->assertDontSee('At the last head count')
            ->assertDontSee($travellers[0]->full_name);
    }

    public function test_when_the_pilgrim_turns_it_on_the_head_count_appears(): void
    {
        [$booking, $travellers] = $this->booking();

        $rollCall = RollCall::create([
            'departure_id' => $booking->departure_id,
            'moment' => 'Boarding at Velana',
            'taken_at' => now(),
        ]);

        RollCallMark::create([
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::PRESENT,
        ]);

        $this->enter($booking, sharesAttendance: true);

        $this->get(route('family.home'))
            ->assertOk()
            ->assertSee('At the last head count')
            ->assertSee($travellers[0]->full_name)
            ->assertSee('Accounted for');
    }

    /**
     * A family must never learn who else is on the trip, at any setting.
     *
     * The attendance list is built from this booking's travellers, not from
     * the roll call's marks — which is the difference between showing your
     * own family and showing the whole coach.
     */
    public function test_a_family_never_sees_somebody_from_another_booking(): void
    {
        [$booking, $travellers] = $this->booking();

        $stranger = Traveller::factory()->create(['full_name' => 'Somebody Else Entirely']);
        $otherBooking = Booking::factory()->create([
            'departure_id' => $booking->departure_id,
            'customer_id' => Customer::factory()->create()->getKey(),
        ]);
        $otherBooking->forceFill(['status' => Booking::CONFIRMED])->save();
        $otherBooking->travellers()->create([
            'traveller_id' => $stranger->getKey(),
            'occupancy' => 'quad',
        ]);

        $rollCall = RollCall::create([
            'departure_id' => $booking->departure_id,
            'moment' => 'Boarding at Velana',
            'taken_at' => now(),
        ]);

        RollCallMark::create([
            'roll_call_id' => $rollCall->getKey(),
            'traveller_id' => $travellers[0]->getKey(),
            'state' => RollCallMark::PRESENT,
        ]);

        $this->enter($booking, sharesAttendance: true);

        $this->get(route('family.home'))
            ->assertOk()
            ->assertSee($travellers[0]->full_name)
            ->assertDontSee('Somebody Else Entirely');
    }

    /**
     * Turning a link off closes it on whoever is already looking.
     *
     * A control that only takes effect at the next sign-in is not one you
     * own, which is why the access is re-read on every request rather than
     * trusted from the session.
     */
    public function test_revoking_a_link_closes_the_page_on_somebody_mid_session(): void
    {
        [$booking] = $this->booking();
        $access = $this->enter($booking);

        $this->get(route('family.home'))->assertOk();

        app(Doorkeeper::class)->revoke($access);

        $this->get(route('family.home'))->assertRedirect(route('family.locked'));
    }

    public function test_an_expired_link_says_why(): void
    {
        [$booking] = $this->booking();
        $token = app(Doorkeeper::class)->issue($booking);

        FamilyAccess::sole()->forceFill(['expires_at' => now()->subDay()])->save();

        $this->get(route('family.enter', ['token' => $token]))->assertRedirect(route('family.locked'));
        $this->get(route('family.locked'))->assertOk()->assertSee('expired');
    }

    // ── What is never shown ──────────────────────────────────────────────

    /**
     * Money, documents and passport details, at any setting.
     *
     * A family link will be forwarded into a group chat. That is not a risk
     * to mitigate, it is the expected use.
     */
    public function test_a_family_page_carries_no_money_and_no_passport(): void
    {
        [$booking, $travellers] = $this->booking();

        $booking->forceFill(['total_minor' => 2_850_000, 'paid_minor' => 1_000_000])->save();
        $travellers[0]->forceFill(['passport_number' => 'A1234567'])->save();

        $this->enter($booking, sharesAttendance: true);

        $response = $this->get(route('family.home'))->assertOk();

        $response->assertDontSee('A1234567');
        $response->assertDontSee('28,500');
        $response->assertDontSee('18,500');
        $response->assertDontSee($booking->reference);
    }

    /**
     * A family session must never satisfy a pilgrim-portal check.
     *
     * The two carry different amounts of somebody's life, which is why they
     * are separate middleware over separate session keys rather than one
     * gate with a mode.
     */
    public function test_a_family_session_is_not_a_pilgrim_session(): void
    {
        [$booking] = $this->booking();
        $this->enter($booking);

        $this->get(route('portal.home'))->assertRedirect(route('portal.locked'));
        $this->get(route('portal.documents'))->assertRedirect(route('portal.locked'));
        $this->get(route('portal.invoice'))->assertRedirect(route('portal.locked'));
    }

    // ── The pilgrim's screen ─────────────────────────────────────────────

    private function asPilgrim(Booking $booking): void
    {
        $token = app(Gatekeeper::class)->issue($booking);
        $this->get(route('portal.enter', ['token' => $token]));
    }

    public function test_the_pilgrim_can_make_a_link_and_is_shown_it_once(): void
    {
        [$booking] = $this->booking();
        $this->asPilgrim($booking);

        $this->post(route('portal.family.store'), ['label' => 'Mum'])
            ->assertRedirect(route('portal.family'));

        $this->assertSame(1, FamilyAccess::count());
        $this->assertFalse(FamilyAccess::sole()->shares_attendance);

        // Once: the link is in the flash, so it is gone on a refresh.
        $this->get(route('portal.family'))->assertOk()->assertSee('/family/enter/');
        $this->get(route('portal.family'))->assertOk()->assertDontSee('/family/enter/');
    }

    public function test_the_pilgrim_can_turn_sharing_on_and_off_again(): void
    {
        [$booking] = $this->booking();
        $this->asPilgrim($booking);

        $access = app(Doorkeeper::class)->issue($booking, 'Mum', false);
        $link = FamilyAccess::sole();

        $this->patch(route('portal.family.update', $link), ['shares_attendance' => '1']);
        $this->assertTrue($link->fresh()->shares_attendance);

        // Off has to be as easy as on, or it is a one-way door dressed up
        // as a choice.
        $this->patch(route('portal.family.update', $link), ['shares_attendance' => '0']);
        $this->assertFalse($link->fresh()->shares_attendance);
    }

    public function test_the_pilgrim_can_turn_a_link_off(): void
    {
        [$booking] = $this->booking();
        $this->asPilgrim($booking);

        app(Doorkeeper::class)->issue($booking);
        $link = FamilyAccess::sole();

        $this->delete(route('portal.family.revoke', $link))->assertRedirect(route('portal.family'));

        $this->assertFalse($link->fresh()->isLive());
    }

    /** A pilgrim must not reach somebody else's link by editing the URL. */
    public function test_a_pilgrim_cannot_touch_another_bookings_link(): void
    {
        [$mine] = $this->booking();
        [$theirs] = $this->booking();

        app(Doorkeeper::class)->issue($theirs);
        $notMine = FamilyAccess::where('booking_id', $theirs->getKey())->sole();

        $this->asPilgrim($mine);

        $this->delete(route('portal.family.revoke', $notMine))->assertNotFound();
        $this->patch(route('portal.family.update', $notMine), ['shares_attendance' => '1'])->assertNotFound();

        $this->assertTrue($notMine->fresh()->isLive());
        $this->assertFalse($notMine->fresh()->shares_attendance);
    }

    /** Staff have no path to minting one. §6.2 makes it the pilgrim's. */
    public function test_there_is_no_staff_route_to_issue_a_family_link(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->filter(fn (string $uri): bool => str_contains($uri, 'family'));

        foreach ($routes as $uri) {
            $this->assertStringNotContainsString('staff/', $uri, "[{$uri}] puts a family link in staff hands.");
            $this->assertStringNotContainsString('admin/', $uri, "[{$uri}] puts a family link in staff hands.");
        }
    }

    // ── Announcements ────────────────────────────────────────────────────

    public function test_a_published_announcement_reaches_the_family(): void
    {
        [$booking] = $this->booking();

        Announcement::create([
            'departure_id' => $booking->departure_id,
            'headline' => 'The group reached Madinah safely.',
            'published_at' => now()->subHour(),
        ]);

        $this->enter($booking);

        $this->get(route('family.home'))->assertOk()->assertSee('reached Madinah safely');
    }

    /** A draft written at three in the morning is not on a family's screen. */
    public function test_an_unpublished_announcement_does_not(): void
    {
        [$booking] = $this->booking();

        Announcement::create([
            'departure_id' => $booking->departure_id,
            'headline' => 'Draft nobody meant to send',
        ]);

        $this->enter($booking);

        $this->get(route('family.home'))->assertOk()->assertDontSee('Draft nobody meant to send');
    }

    /**
     * `whereNotNull` alone would put a post-dated announcement on screen the
     * moment somebody saved it, which is the one thing scheduling it was
     * for.
     */
    public function test_an_announcement_dated_for_tomorrow_waits(): void
    {
        [$booking] = $this->booking();

        Announcement::create([
            'departure_id' => $booking->departure_id,
            'headline' => 'Tomorrow is the flight home',
            'published_at' => now()->addDay(),
        ]);

        $this->enter($booking);

        $this->get(route('family.home'))->assertOk()->assertDontSee('Tomorrow is the flight home');
    }

    public function test_an_announcement_for_another_departure_is_not_shown(): void
    {
        [$booking] = $this->booking();
        [$other] = $this->booking();

        Announcement::create([
            'departure_id' => $other->departure_id,
            'headline' => 'A different group entirely',
            'published_at' => now()->subHour(),
        ]);

        $this->enter($booking);

        $this->get(route('family.home'))->assertOk()->assertDontSee('A different group entirely');
    }

    // ── Journey progress ─────────────────────────────────────────────────

    public function test_progress_before_the_departure_counts_down(): void
    {
        $departure = Departure::factory()->create([
            'date_start' => now()->addDays(5),
            'date_end' => now()->addDays(19),
        ]);

        $progress = JourneyProgress::of($departure);

        $this->assertSame(JourneyProgress::BEFORE, $progress['stage']);
        $this->assertStringContainsString('5 days', $progress['headline']);
    }

    public function test_progress_after_the_return_says_it_is_over(): void
    {
        $departure = Departure::factory()->create([
            'date_start' => now()->subDays(20),
            'date_end' => now()->subDays(6),
        ]);

        $this->assertSame(JourneyProgress::HOME, JourneyProgress::of($departure)['stage']);
    }

    /** From the recorded hotel nights, not from the day number. */
    public function test_progress_names_the_city_from_the_recorded_nights(): void
    {
        $departure = Departure::factory()->create([
            'date_start' => now()->subDays(3),
            'date_end' => now()->addDays(11),
        ]);

        DepartureHotel::factory()->create([
            'departure_id' => $departure->getKey(),
            'city' => DepartureHotel::CITY_MAKKAH,
            'nights' => 7,
        ]);

        DepartureHotel::factory()->create([
            'departure_id' => $departure->getKey(),
            'city' => DepartureHotel::CITY_MADINAH,
            'nights' => 7,
        ]);

        $this->assertSame(JourneyProgress::IN_MAKKAH, JourneyProgress::of($departure->fresh())['stage']);
    }

    /**
     * The honest answer when nobody recorded the nights.
     *
     * Guessing from the day number would be wrong on the trip where the
     * coach broke down — and that is the trip a family is refreshing this
     * page on.
     */
    public function test_progress_says_it_does_not_know_rather_than_guessing(): void
    {
        $departure = Departure::factory()->create([
            'date_start' => now()->subDays(3),
            'date_end' => now()->addDays(11),
        ]);

        $progress = JourneyProgress::of($departure);

        $this->assertSame(JourneyProgress::ON_THE_TRIP, $progress['stage']);
        $this->assertStringNotContainsString('Makkah', $progress['headline']);
        $this->assertStringNotContainsString('Madinah', $progress['headline']);
    }

    /** Nothing records a location, so nothing can leak one. */
    public function test_nothing_in_the_progress_payload_is_a_location(): void
    {
        [$booking] = $this->booking();
        $this->enter($booking, sharesAttendance: true);

        $this->get(route('family.home'))
            ->assertOk()
            ->assertSee('no location for anyone', false);
    }
}
