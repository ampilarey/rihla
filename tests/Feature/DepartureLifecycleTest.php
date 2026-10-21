<?php

namespace Tests\Feature;

use App\Exceptions\IllegalDepartureTransition;
use App\Filament\Pages\DepartureBoard;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\RelationManagers\DeparturesRelationManager;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Document;
use App\Models\Package;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Booking\BookingDesk;
use App\Services\Booking\SeatAllocator;
use App\Services\Documents\DocumentWallet;
use App\Support\Access;
use App\Support\DepartureReadiness;
use App\Support\TravelReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A departure's lifecycle, moved by hand — batch C from Hijaz. The stage
 * decides what the dates and the publish switch used to; the dates only
 * say when a move looks overdue.
 */
class DepartureLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /** @param  array<string, mixed>  $attributes */
    private function departure(array $attributes = []): Departure
    {
        return Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addDays(40),
            'date_end' => now()->addDays(54),
            ...$attributes,
        ]);
    }

    private function liveBooking(Departure $departure): Booking
    {
        $customer = Customer::factory()->create();
        $booking = Booking::factory()->create(['customer_id' => $customer->getKey(), 'departure_id' => $departure->getKey(), 'seats' => 1]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        return $booking;
    }

    public function test_a_departure_walks_its_stages_with_a_line_in_its_history_each_time(): void
    {
        $ops = $this->staff(Access::OPERATIONS_MANAGER);
        $this->actingAs($ops);
        $departure = $this->departure();

        $this->assertSame(Departure::OPEN, $departure->status, 'The factory opens it, as the publish switch used to.');
        $this->assertTrue($departure->is_published);
        $this->assertTrue($departure->acceptsBookings());

        $departure->transitionTo(Departure::CLOSED, 'Flights full');
        $departure->refresh();
        $this->assertSame(Departure::CLOSED, $departure->status);
        $this->assertFalse($departure->is_published, 'is_published follows the stage.');
        $this->assertFalse($departure->acceptsBookings());
        $this->assertNotNull($departure->status_changed_at);

        $event = $departure->latestEvent();
        $this->assertSame(Departure::OPEN, $event?->from_status);
        $this->assertSame(Departure::CLOSED, $event?->to_status);
        $this->assertSame($ops->getKey(), $event?->user_id);
        $this->assertSame('Flights full', $event?->reason);

        $departure->transitionTo(Departure::OPEN);
        $this->assertTrue($departure->fresh()->is_published, 'Reopened: on sale again.');

        $departure->transitionTo(Departure::DEPARTED);
        $departure->transitionTo(Departure::RETURNED);
        $departure->transitionTo(Departure::SETTLED, 'Books closed');
        $this->assertSame(Departure::SETTLED, $departure->fresh()->status);
        $this->assertTrue($departure->isHistory());
        $this->assertSame(5, $departure->events()->count());

        $this->expectException(IllegalDepartureTransition::class);
        $this->expectExceptionMessage('A departure that is Settled cannot become Open for booking. Allowed from here: nothing');
        $departure->transitionTo(Departure::OPEN);
    }

    public function test_a_draft_is_hidden_unbookable_and_opens_only_with_seats_and_a_date_ahead(): void
    {
        $departure = $this->departure(['status' => Departure::DRAFT, 'capacity_total' => 0]);

        $this->assertFalse($departure->is_published);
        $this->assertFalse($departure->is_bookable);
        $this->assertSame(0, Departure::open()->count());

        try {
            $departure->transitionTo(Departure::OPEN);
            $this->fail('Opened with no seats.');
        } catch (IllegalDepartureTransition $e) {
            $this->assertStringContainsString('Set the seats', $e->getMessage());
        }

        $departure->forceFill(['capacity_total' => 20, 'date_start' => now()->subDay(), 'date_end' => now()->addDays(13)])->save();

        try {
            $departure->transitionTo(Departure::OPEN);
            $this->fail('Opened after leaving.');
        } catch (IllegalDepartureTransition $e) {
            $this->assertStringContainsString('cannot be opened for booking', $e->getMessage());
        }

        $departure->forceFill(['date_start' => now()->addDays(40), 'date_end' => now()->addDays(54)])->save();
        $departure->transitionTo(Departure::OPEN);

        $this->assertTrue($departure->fresh()->is_bookable);
        $this->assertSame(1, Departure::open()->count());
    }

    public function test_cancelling_waits_for_every_live_booking_to_go(): void
    {
        $departure = $this->departure();
        $booking = $this->liveBooking($departure);

        try {
            $departure->transitionTo(Departure::CANCELLED, 'Airline pulled the flight');
            $this->fail('Cancelled over a confirmed seat.');
        } catch (IllegalDepartureTransition $e) {
            $this->assertStringContainsString('1 live booking first', $e->getMessage());
        }

        $booking->forceFill(['status' => Booking::CANCELLED])->save();
        $departure->transitionTo(Departure::CANCELLED, 'Airline pulled the flight');

        $this->assertTrue($departure->fresh()->isCancelled());
        $this->assertSame([], DepartureReadiness::concerns($departure->fresh()), 'History has nothing to be ready for.');
    }

    public function test_no_seat_can_be_taken_on_a_departure_that_is_not_open_by_any_door(): void
    {
        $this->actingAs($this->staff(Access::BOOKING_STAFF));
        $departure = $this->departure();
        $departure->priceTiers()->create(['occupancy' => 'double', 'pax_type' => 'adult', 'amount_minor' => 3_200_000]);
        $customer = Customer::factory()->create();
        $traveller = Traveller::factory()->for($customer)->create(['date_of_birth' => now()->subYears(40)]);

        $departure->transitionTo(Departure::CLOSED, 'Flights full');

        try {
            app(SeatAllocator::class)->hold($departure->fresh(), 1);
            $this->fail('A hold was taken on a closed departure.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This departure is closed to booking; no seat can be taken on it.', $e->getMessage());
        }

        try {
            app(BookingDesk::class)->open($departure->fresh(), $customer, [['traveller_id' => $traveller->getKey(), 'occupancy' => 'double']]);
            $this->fail('The desk booked a closed departure.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('closed to booking', $e->getMessage());
        }

        $this->assertSame(0, Booking::query()->count());
        $this->assertSame(0, $departure->fresh()->capacity_held);

        // The public checkout never gets as far as the allocator.
        $this->get('/en/packages/'.$departure->package->slug)->assertOk()->assertDontSee('Book now');
    }

    public function test_the_board_says_when_a_stage_looks_overdue_and_nothing_once_it_is_history(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER));
        $headlines = fn (Departure $departure): array => array_column(
            array_filter(DepartureReadiness::concerns($departure->fresh()), fn (array $c): bool => $c['area'] === DepartureReadiness::LIFECYCLE),
            'headline',
        );

        $this->assertSame([], $headlines($this->departure()), 'Open, leaving in forty days: nothing to say.');
        $this->assertSame(['Not open for booking yet'], $headlines($this->departure(['status' => Departure::DRAFT])));
        $this->assertSame([], $headlines($this->departure(['status' => Departure::DRAFT, 'date_start' => now()->addDays(90), 'date_end' => now()->addDays(104)])), 'A draft three months out is nobody\'s concern yet.');

        $left = ['date_start' => now()->subDays(2), 'date_end' => now()->addDays(12)];
        $this->assertSame(['Still open for booking after leaving'], $headlines($this->departure($left)));
        $this->assertSame([], $headlines($this->departure(['date_start' => today(), 'date_end' => now()->addDays(14)])), 'Leaving today is not late until tomorrow.');
        $this->assertSame(['Left on '.now()->subDays(2)->format('j M Y').' and not marked departed'], $headlines($this->departure($left + ['status' => Departure::CLOSED])));
        $this->assertSame([], $headlines($this->departure($left + ['status' => Departure::DEPARTED])));

        $due = ['date_start' => now()->subDays(20), 'date_end' => now()->subDays(3), 'status' => Departure::DEPARTED];
        $this->assertSame(['Due home on '.now()->subDays(3)->format('j M Y').' and not marked returned'], $headlines($this->departure($due)));

        $home = $this->departure(['date_start' => now()->subDays(40), 'date_end' => now()->subDays(26), 'status' => Departure::DEPARTED]);
        $home->transitionTo(Departure::RETURNED);
        $this->assertSame([], $headlines($home), 'Just home: give Finance a fortnight.');
        $home->forceFill(['status_changed_at' => now()->subDays(20)])->save();
        $this->assertSame(['Home for 20 days and not settled'], $headlines($home));

        // The board lists what is somebody's work and nothing else.
        $far = $this->departure(['status' => Departure::DRAFT, 'date_start' => now()->addDays(90), 'date_end' => now()->addDays(104), 'package_id' => Package::factory()->create(['title' => ['en' => 'Far draft']])->getKey()]);
        $this->departure(['status' => Departure::CANCELLED, 'package_id' => Package::factory()->create(['title' => ['en' => 'Pulled']])->getKey()]);

        Livewire::test(DepartureBoard::class)
            ->assertOk()
            ->assertSee('Due home on')
            ->assertSee('Home for 20 days and not settled')
            ->assertDontSee('Far draft')
            ->assertDontSee('Pulled');

        $this->assertFalse($far->fresh()->is_published);

        $home->transitionTo(Departure::SETTLED);
        $this->assertSame([], DepartureReadiness::concerns($home->fresh()));
        Livewire::test(DepartureBoard::class)->assertDontSee('Home for 20 days');
    }

    public function test_the_stage_is_moved_from_the_list_by_the_people_allowed_to(): void
    {
        $ops = $this->staff(Access::OPERATIONS_MANAGER);
        $departure = $this->departure();
        $package = $departure->package;
        $manager = fn (User $user) => Livewire::actingAs($user)->test(DeparturesRelationManager::class, ['ownerRecord' => $package, 'pageClass' => EditPackage::class]);

        $manager($ops)
            ->assertTableActionVisible('move_closed', $departure)
            ->assertTableActionVisible('move_cancelled', $departure)
            ->assertTableActionHidden('move_settled', $departure)
            ->assertTableActionHidden('move_returned', $departure)
            ->callTableAction('move_closed', $departure, data: ['reason' => 'Flights full'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Close bookings');

        $this->assertSame(Departure::CLOSED, $departure->fresh()->status);
        $this->assertSame('Flights full', $departure->latestEvent()?->reason);

        // The website's editor edits content and moves nothing.
        $manager($this->staff(Access::CONTENT_MANAGER))
            ->assertTableActionHidden('move_open', $departure)
            ->assertTableActionHidden('move_departed', $departure);

        // A live booking stops a cancellation, and the screen says so.
        $this->liveBooking($departure);
        $manager($ops)
            ->callTableAction('move_cancelled', $departure, data: ['reason' => 'Airline pulled the flight'])
            ->assertNotified('Not moved');
        $this->assertSame(Departure::CLOSED, $departure->fresh()->status);

        // The board offers the moves that need no reason, to whoever may
        // make them: the party flies and comes home under Operations, and
        // Finance settles it from here — the package's departures list is
        // not a screen Finance opens.
        $finance = $this->staff(Access::FINANCE);
        $board = fn (User $user) => Livewire::actingAs($user)->test(DepartureBoard::class);

        $this->assertSame(['departed' => 'Mark departed'], $board($ops)->instance()->movesFor($departure->fresh()));
        $this->assertSame([], $board($finance)->instance()->movesFor($departure->fresh()));

        $board($ops)->call('move', $departure->getKey(), Departure::DEPARTED)->assertNotified('Mark departed');
        $board($ops)->call('move', $departure->getKey(), Departure::RETURNED)->assertNotified('Mark returned');
        $this->assertSame(Departure::RETURNED, $departure->fresh()->status);

        $this->assertSame(['settled' => 'Settle'], $board($ops)->instance()->movesFor($departure->fresh()), 'Operations may settle as well as Finance.');
        $this->assertSame(['settled' => 'Settle'], $board($finance)->instance()->movesFor($departure->fresh()));
        $this->assertSame([], $board($this->staff(Access::BOOKING_STAFF))->instance()->movesFor($departure->fresh()), 'The desk moves nothing.');
        $board($finance)->call('move', $departure->getKey(), Departure::SETTLED)->assertNotified('Settle');
        $this->assertTrue($departure->fresh()->isSettled());

        // Nothing the board does not offer, and nothing twice.
        $board($finance)->call('move', $departure->getKey(), Departure::SETTLED)->assertNotified('Not moved');
        $board($ops)->call('move', $departure->getKey(), Departure::OPEN)->assertStatus(422);
    }

    public function test_the_passport_window_is_counted_from_the_day_the_journey_comes_home(): void
    {
        Storage::fake('documents');
        $this->actingAs($this->staff(Access::VISA_STAFF));
        $departure = $this->departure();
        $customer = Customer::factory()->create();
        $traveller = Traveller::factory()->for($customer)->create();
        $booking = Booking::factory()->create(['customer_id' => $customer->getKey(), 'departure_id' => $departure->getKey(), 'seats' => 1]);
        $booking->travellers()->create(['traveller_id' => $traveller->getKey(), 'occupancy' => 'double', 'is_lead' => true]);

        // Good for six months and five days past the outbound flight — and
        // so not for six months past the return, fourteen days later.
        $wallet = app(DocumentWallet::class);
        $version = $wallet->store($traveller, UploadedFile::fake()->create('passport.pdf', 10, 'application/pdf'), Document::IDENTITY, Document::PASSPORT, [
            'expires_at' => $departure->date_start->copy()->addMonths(6)->addDays(5)->toDateString(),
        ]);
        $wallet->verify($version->document);

        $this->assertSame('return', config('documents.passport_validity_from'));
        $this->assertFalse(TravelReadiness::forTraveller($booking, $traveller)[TravelReadiness::PASSPORT], 'Counted from the return: too short.');

        config(['documents.passport_validity_from' => 'departure']);
        $this->assertTrue(TravelReadiness::forTraveller($booking, $traveller)[TravelReadiness::PASSPORT], 'Counted from the departure, as before: long enough.');
    }
}
