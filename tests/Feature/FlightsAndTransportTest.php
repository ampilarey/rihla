<?php

namespace Tests\Feature;

use App\Filament\Resources\Flights\DepartureFlightResource;
use App\Filament\Resources\Flights\Pages\ListDepartureFlights;
use App\Filament\Resources\Transfers\DepartureTransferResource;
use App\Filament\Resources\Transfers\Pages\ListDepartureTransfers;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureFlight;
use App\Models\DepartureTransfer;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Family\Doorkeeper;
use App\Services\Portal\Gatekeeper;
use App\Support\Access;
use App\Support\Anonymisation;
use App\Support\DepartureReadiness;
use App\Support\Forgetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Flights and ground transport — §8.3.
 *
 * The departure board used to say, on its face, that nothing recorded
 * them. These are the records, and what each audience is allowed to see of
 * them: the office everything, the pilgrim and the family times and
 * meeting points, and nobody outside the office the booking reference or
 * the driver's number.
 */
class FlightsAndTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults(['locale' => 'en']);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /** A departure leaving in three weeks with $people confirmed travellers. */
    private function departure(int $people = 2): Departure
    {
        $departure = Departure::factory()->withSeats(40)->create([
            'date_start' => now()->addDays(21),
            'date_end' => now()->addDays(35),
        ]);

        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => max($people, 1),
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        for ($i = 0; $i < $people; $i++) {
            $booking->travellers()->create([
                'traveller_id' => Traveller::factory()->for($customer)->create()->getKey(),
                'occupancy' => 'quad',
                'is_lead' => $i === 0,
            ]);
        }

        return $departure;
    }

    /** @param array<string, mixed> $overrides */
    private function flight(Departure $departure, array $overrides = []): DepartureFlight
    {
        return DepartureFlight::create(array_merge([
            'departure_id' => $departure->getKey(),
            'direction' => DepartureFlight::OUTBOUND,
            'airline' => 'Saudia',
            'flight_number' => 'SV 3301',
            'from_airport' => 'MLE',
            'to_airport' => 'JED',
            'departs_at' => '2027-03-14 02:15:00',
            'arrives_at' => '2027-03-14 06:40:00',
            'booking_reference' => 'QX7PNR',
        ], $overrides));
    }

    /** @return list<string> */
    private function headlines(Departure $departure): array
    {
        return array_column(DepartureReadiness::concerns($departure), 'headline');
    }

    // ── The departure board ──────────────────────────────────────────────

    public function test_a_departure_people_are_travelling_on_says_its_flights_are_missing(): void
    {
        $headlines = $this->headlines($this->departure());

        $this->assertContains('No flights recorded', $headlines);
        $this->assertContains('No ground transport recorded', $headlines);
    }

    /** Silent until somebody is travelling, or every unsold date nags. */
    public function test_an_unsold_departure_does_not_nag_about_flights(): void
    {
        $headlines = $this->headlines($this->departure(people: 0));

        $this->assertNotContains('No flights recorded', $headlines);
        $this->assertNotContains('No ground transport recorded', $headlines);
    }

    public function test_the_way_home_is_asked_for(): void
    {
        $departure = $this->departure();
        $this->flight($departure);

        $headlines = $this->headlines($departure);

        $this->assertNotContains('No flights recorded', $headlines);
        $this->assertContains('No return flight recorded', $headlines);
    }

    /** More people than seats on a leg is somebody who cannot fly. */
    public function test_a_leg_without_enough_seats_blocks(): void
    {
        $departure = $this->departure(people: 3);
        $this->flight($departure, ['seats' => 2]);

        $shortfall = collect(DepartureReadiness::concerns($departure))
            ->first(fn (array $c): bool => str_contains($c['headline'], 'one seat short'));

        $this->assertNotNull($shortfall, 'Three travellers on a two-seat leg was not reported.');
        $this->assertSame(DepartureReadiness::BLOCKING, $shortfall['severity']);
        $this->assertStringContainsString('SV 3301, MLE → JED', $shortfall['headline']);
        $this->assertTrue(DepartureReadiness::hasBlockers($departure));
    }

    /** An unknown seat count is not a shortfall. */
    public function test_a_leg_with_no_seat_count_is_not_compared(): void
    {
        $departure = $this->departure(people: 3);
        $this->flight($departure, ['seats' => null]);
        $this->flight($departure, ['direction' => DepartureFlight::RETURN, 'from_airport' => 'JED', 'to_airport' => 'MLE']);
        DepartureTransfer::create([
            'departure_id' => $departure->getKey(), 'starts_at' => '2027-03-14 08:00:00',
            'mode' => DepartureTransfer::COACH, 'from_place' => 'Jeddah airport', 'to_place' => 'Makkah hotel',
        ]);

        $flightConcerns = collect(DepartureReadiness::concerns($departure))
            ->whereIn('area', [DepartureReadiness::FLIGHTS, DepartureReadiness::TRANSPORT]);

        $this->assertCount(0, $flightConcerns, 'A complete record still raised: '.$flightConcerns->pluck('headline')->implode('; '));
    }

    // ── What the pilgrim sees ────────────────────────────────────────────

    public function test_the_pilgrim_sees_times_and_meeting_points_and_not_the_references(): void
    {
        $departure = $this->departure();
        $this->flight($departure);
        DepartureTransfer::create([
            'departure_id' => $departure->getKey(),
            'starts_at' => '2027-03-14 08:00:00',
            'mode' => DepartureTransfer::COACH,
            'from_place' => 'Jeddah airport',
            'to_place' => 'Makkah hotel',
            'meeting_point' => 'Arrivals hall, by exit 4',
            'provider' => 'Al Haramain Transport',
            'contact_phone' => '+966 50 123 4567',
        ]);

        $booking = Booking::where('departure_id', $departure->getKey())->sole();
        $this->get(route('portal.enter', ['token' => app(Gatekeeper::class)->issue($booking)]));

        $this->get(route('portal.home'))
            ->assertOk()
            ->assertSee('SV 3301')
            ->assertSee('Sun 14 Mar, 02:15')
            ->assertSee('Arrivals hall, by exit 4')
            ->assertDontSee('QX7PNR')
            ->assertDontSee('+966 50 123 4567');
    }

    public function test_the_family_sees_when_to_meet_them_and_not_the_reference(): void
    {
        $departure = $this->departure();
        $this->flight($departure, [
            'direction' => DepartureFlight::RETURN, 'flight_number' => 'SV 3302',
            'from_airport' => 'JED', 'to_airport' => 'MLE',
            'departs_at' => '2027-03-28 09:10:00', 'arrives_at' => '2027-03-28 16:55:00',
        ]);

        $booking = Booking::where('departure_id', $departure->getKey())->sole();
        $this->get(route('family.enter', ['token' => app(Doorkeeper::class)->issue($booking, 'Mum')]));

        $this->get(route('family.home'))
            ->assertOk()
            ->assertSee('SV 3302')
            ->assertSee('arrives Sun 28 Mar, 16:55')
            ->assertDontSee('QX7PNR');
    }

    // ── The staff screens ────────────────────────────────────────────────

    public function test_operations_records_a_flight(): void
    {
        $departure = $this->departure();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListDepartureFlights::class)
            ->callAction('create', data: [
                'departure_id' => $departure->getKey(),
                'direction' => DepartureFlight::OUTBOUND,
                'airline' => 'Saudia',
                'flight_number' => 'SV 3301',
                'from_airport' => 'mle',
                'to_airport' => 'jed',
                'departs_at' => '2027-03-14 02:15:00',
                'seats' => 30,
            ])
            ->assertHasNoActionErrors();

        $flight = DepartureFlight::sole();

        // Upper-cased, and the time stored exactly as typed.
        $this->assertSame('MLE', $flight->from_airport);
        $this->assertSame('2027-03-14 02:15:00', $flight->getRawOriginal('departs_at'));
    }

    public function test_an_airport_code_must_be_three_letters(): void
    {
        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListDepartureFlights::class)
            ->callAction('create', data: [
                'departure_id' => $this->departure()->getKey(),
                'direction' => DepartureFlight::OUTBOUND,
                'airline' => 'Saudia',
                'flight_number' => 'SV 3301',
                'from_airport' => 'Malé',
                'to_airport' => 'JED',
                'departs_at' => '2027-03-14 02:15:00',
            ])
            ->assertHasActionErrors(['from_airport']);

        $this->assertSame(0, DepartureFlight::count());
    }

    public function test_operations_records_a_transfer(): void
    {
        $departure = $this->departure();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListDepartureTransfers::class)
            ->callAction('create', data: [
                'departure_id' => $departure->getKey(),
                'mode' => DepartureTransfer::COACH,
                'from_place' => 'Jeddah airport',
                'to_place' => 'Makkah hotel',
                'starts_at' => '2027-03-14 08:00:00',
                'meeting_point' => 'Arrivals hall',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('Arrivals hall', DepartureTransfer::sole()->meeting_point);
    }

    /** The tour leader reads them; changing them is the office's. */
    public function test_the_tour_leader_reads_and_does_not_write(): void
    {
        $leader = $this->staff(Access::TOUR_LEADER);
        $this->flight($this->departure());

        $this->actingAs($leader)->get(DepartureFlightResource::getUrl('index'))->assertOk();
        $this->actingAs($leader)->get(DepartureTransferResource::getUrl('index'))->assertOk();

        Livewire::actingAs($leader)
            ->test(ListDepartureFlights::class)
            ->assertActionHidden('create')
            ->assertTableActionHidden('edit', DepartureFlight::sole())
            ->assertTableActionHidden('delete', DepartureFlight::sole());
    }

    public function test_the_content_manager_does_not_see_them(): void
    {
        $manager = $this->staff(Access::CONTENT_MANAGER);

        $this->actingAs($manager)->get(DepartureFlightResource::getUrl('index'))->assertForbidden();
        $this->actingAs($manager)->get(DepartureTransferResource::getUrl('index'))->assertForbidden();
    }

    // ── Personal data ────────────────────────────────────────────────────

    /** Classified in both lists, in the same commit — AGENTS.md. */
    public function test_both_tables_are_classified_for_scrubbing_and_forgetting(): void
    {
        $this->assertContains('departure_flights', Anonymisation::classified());
        $this->assertContains('departure_transfers', Anonymisation::classified());
        $this->assertArrayHasKey('contact_phone', Anonymisation::SCRUB['departure_transfers']);
        $this->assertSame([], Forgetting::unreached());
    }

    public function test_they_go_with_their_departure(): void
    {
        $departure = $this->departure(people: 0);
        $this->flight($departure);

        $departure->bookings()->delete();
        $departure->delete();

        $this->assertSame(0, DepartureFlight::count());
    }
}
