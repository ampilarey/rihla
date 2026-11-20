<?php

namespace Modules\Umrah\Tests;

use App\Exceptions\DeskRefusal;
use App\Filament\Pages\Forecast;
use App\Filament\Pages\Lists;
use App\Filament\Pages\Profitability;
use App\Filament\Pages\Today;
use App\Filament\Support\DepartureOptions;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Support\Access;
use App\Support\DepartureReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Modules\Telegram\Services\DailySummary;
use Modules\Umrah\Exceptions\IllegalDepartureTransition;
use Modules\Umrah\Filament\Pages\DepartureBoard;
use Modules\Umrah\Filament\Resources\Bookings\BookingResource;
use Modules\Umrah\Filament\Resources\Packages\PackageResource;
use Modules\Umrah\Filament\Resources\Packages\Pages\EditPackage;
use Modules\Umrah\Filament\Resources\Packages\RelationManagers\DeparturesRelationManager;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\DepartureFlight;
use Modules\Umrah\Models\Traveller;
use Modules\Umrah\Services\Booking\BookingDesk;
use Tests\TestCase;

/**
 * Phase 3b of docs/UMRAH_COMPANIES_PLAN.md, from Hijaz: a departure saved
 * and booked before its dates are fixed, its dates taken from its flights,
 * and registration before a price, with no money taken until there is one.
 */
class DeparturesBeforeDatesAndPricesTest extends TestCase
{
    use RefreshDatabase;

    private function dateless(): Departure
    {
        return Departure::factory()->withSeats(20)->create(['date_start' => null, 'date_end' => null]);
    }

    private function party(Departure $departure, string $occupancy = 'quad'): Booking
    {
        $customer = Customer::factory()->create();
        $traveller = Traveller::factory()->for($customer)->create(['date_of_birth' => now()->subYears(40)]);

        return app(BookingDesk::class)->open($departure, $customer, [['traveller_id' => $traveller->id, 'occupancy' => $occupancy]]);
    }

    public function test_a_departure_without_dates_takes_bookings_at_the_office_but_is_not_on_the_website(): void
    {
        $departure = $this->dateless();
        $departure->priceTiers()->create(['occupancy' => 'quad', 'pax_type' => 'adult', 'amount_minor' => 2_850_000]);
        $package = $departure->package;

        $booking = $this->party($departure);
        $this->assertSame(2_850_000, $booking->total_minor);
        $this->assertSame('Dates to be set', $departure->datesLabel());

        $this->assertFalse($package->publishedDepartures()->exists(), 'Not offered on the website until it has dates.');
        $this->get('/en/packages/'.$package->slug)->assertOk()->assertDontSee('dates to be set');
    }

    public function test_it_cannot_leave_without_dates(): void
    {
        $departure = $this->dateless();
        $departure->transitionTo(Departure::CLOSED);

        $this->expectException(IllegalDepartureTransition::class);
        $this->expectExceptionMessage('Set the dates');
        $departure->transitionTo(Departure::DEPARTED);
    }

    public function test_the_flights_set_the_dates_and_the_last_landing_home_is_the_last_day(): void
    {
        $departure = $this->dateless();

        $departure->flights()->create(['direction' => DepartureFlight::OUTBOUND, 'airline' => 'SV', 'flight_number' => 'SV101', 'from_airport' => 'MLE', 'to_airport' => 'JED', 'departs_at' => '2027-02-10 23:30', 'arrives_at' => '2027-02-11 04:00']);
        $departure->flights()->create(['direction' => DepartureFlight::OUTBOUND, 'airline' => 'SV', 'flight_number' => 'SV103', 'from_airport' => 'MLE', 'to_airport' => 'JED', 'departs_at' => '2027-02-09 23:30', 'arrives_at' => '2027-02-10 04:00']);
        $home = $departure->flights()->create(['direction' => DepartureFlight::RETURN, 'airline' => 'SV', 'flight_number' => 'SV102', 'from_airport' => 'JED', 'to_airport' => 'MLE', 'departs_at' => '2027-02-24 22:00', 'arrives_at' => '2027-02-25 08:00']);

        $departure->refresh();
        $this->assertSame('2027-02-09', $departure->date_start?->toDateString(), 'The first flight out.');
        $this->assertSame('2027-02-25', $departure->date_end?->toDateString(), 'The day the last flight home lands.');
        $this->assertTrue($departure->datesFromFlights());

        $home->update(['arrives_at' => '2027-02-26 08:00']);
        $this->assertSame('2027-02-26', $departure->fresh()?->date_end?->toDateString(), 'Every change to a flight sets them again.');
    }

    public function test_a_departure_with_no_price_registers_and_takes_no_money_until_priced(): void
    {
        $departure = $this->dateless();
        $booking = $this->party($departure);

        $this->assertTrue($booking->awaitsPrice());
        $this->assertSame(0, $booking->total_minor);

        try {
            Payment::factory()->create(['payable_type' => (new Booking)->getMorphClass(), 'payable_id' => $booking->id, 'amount_minor' => 100_000]);
            $this->fail('Money was taken on a booking with no price.');
        } catch (DeskRefusal $refusal) {
            $this->assertStringContainsString('no price yet', $refusal->getMessage());
        }

        // The price arrives; the waiting traveller is priced at once.
        $departure->priceTiers()->create(['occupancy' => 'quad', 'pax_type' => 'adult', 'amount_minor' => 2_850_000]);

        $booking->refresh();
        $this->assertFalse($booking->awaitsPrice());
        $this->assertSame(2_850_000, $booking->total_minor);
        $this->assertCount(1, $booking->lines, 'A seat line, for the invoice.');

        // And now money can come in.
        Payment::factory()->create(['payable_type' => (new Booking)->getMorphClass(), 'payable_id' => $booking->id, 'amount_minor' => 100_000]);
        $this->assertSame(1, Payment::count());
    }

    public function test_a_fare_already_set_is_never_changed_by_a_later_price(): void
    {
        $departure = $this->dateless();
        $tier = $departure->priceTiers()->create(['occupancy' => 'quad', 'pax_type' => 'adult', 'amount_minor' => 2_850_000]);
        $booking = $this->party($departure);

        $tier->update(['amount_minor' => 3_100_000]);

        $this->assertSame(2_850_000, $booking->fresh()?->total_minor);
    }

    public function test_every_staff_screen_opens_with_a_departure_that_has_no_dates(): void
    {
        $departure = $this->dateless();
        $booking = $this->party($departure);
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);
        $this->actingAs($admin);

        $urls = [
            BookingResource::getUrl('edit', ['record' => $booking]),
            BookingResource::getUrl('index'),
            PackageResource::getUrl('edit', ['record' => $departure->package]),
            DepartureBoard::getUrl(),
            Lists::getUrl(),
            Today::getUrl(),
            Forecast::getUrl(),
            Profitability::getUrl(),
            route('lists.manifest', ['departure' => $departure->id]),
            route('staff.invoice', ['booking' => $booking->id]),
        ];

        foreach ($urls as $url) {
            $this->assertSame(200, $this->get($url)->status(), $url);
        }

        // The tables and the readiness board, which load on their own.
        Livewire::test(DeparturesRelationManager::class, ['ownerRecord' => $departure->package, 'pageClass' => EditPackage::class])
            ->assertOk()
            ->assertSee('Dates to be set', false);
        $this->assertNotEmpty(DepartureReadiness::concerns($departure));
    }

    public function test_once_a_departure_has_prices_a_room_it_does_not_price_is_still_refused(): void
    {
        $departure = $this->dateless();
        $departure->priceTiers()->create(['occupancy' => 'quad', 'pax_type' => 'adult', 'amount_minor' => 2_850_000]);

        $this->expectException(InvalidArgumentException::class);
        $this->party($departure, 'double');
    }

    /**
     * The office's lists of "upcoming" departures asked for a date on or
     * after today, which an undated departure never matches: it could not
     * be picked at the booking desk, for a bus, a room or a broadcast. The
     * public site's `upcoming()` still leaves it out.
     */
    public function test_the_office_lists_offer_a_departure_without_dates_and_the_website_does_not(): void
    {
        $departure = $this->dateless();
        $past = Departure::factory()->withSeats(20)->create(['date_start' => now()->subYear(), 'date_end' => now()->subYear()->addDays(14)]);

        $this->assertArrayHasKey($departure->id, DepartureOptions::all(recentOnly: true));
        $this->assertArrayNotHasKey($past->id, DepartureOptions::all(recentOnly: true));
        $this->assertTrue(Departure::query()->aheadOrUndated()->whereKey($departure->id)->exists());
        $this->assertFalse(Departure::query()->aheadOrUndated()->whereKey($past->id)->exists());
        $this->assertFalse(Departure::query()->upcoming()->whereKey($departure->id)->exists());

        $dated = Departure::factory()->withSeats(20)->create(['date_start' => now()->addMonth(), 'date_end' => now()->addMonth()->addDays(14)]);
        $this->assertSame([$dated->id, $departure->id], DailySummary::upcoming()->pluck('id')->all(), 'Undated after dated.');

        $this->actingAs(User::factory()->create()->assignRole(Access::SUPER_ADMIN));
        $this->get(BookingResource::getUrl('create'))->assertOk()->assertSee(DepartureOptions::label($departure->load('package')));
    }
}
