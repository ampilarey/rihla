<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\RelationManagers\DeparturesRelationManager;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureCost;
use App\Models\Package;
use App\Models\User;
use App\Services\Departures\DepartureCopier;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Copy this departure": the same set-up on new dates.
 *
 * Every run of a package had its prices, hotels, itinerary, flights,
 * transfers and checklist typed in again. The copy carries all of it with
 * every date moved by the same number of days, and leaves behind what
 * belonged to the run that happened.
 */
class DepartureCopyTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    private Departure $departure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->package = Package::factory()->create(['title' => ['en' => 'Shawwal Umrah']]);

        $this->departure = Departure::factory()->create([
            'package_id' => $this->package->getKey(),
            'date_start' => '2027-03-10',
            'date_end' => '2027-03-24',
            'airline' => 'Maldivian',
            'capacity_total' => 40,
            'capacity_held' => 3,
            'capacity_confirmed' => 12,
            'is_published' => true,
            'nusuk_accommodation_recorded_at' => '2027-02-01 10:00:00',
        ]);

        $this->departure->priceTiers()->create(['occupancy' => 'double', 'pax_type' => 'adult', 'amount_minor' => 2_850_000, 'currency' => 'MVR', 'sort_order' => 1]);
        $this->departure->priceTiers()->create(['occupancy' => 'quad', 'pax_type' => 'child', 'amount_minor' => 1_950_000, 'currency' => 'MVR', 'sort_order' => 2]);
        $this->departure->hotels()->create(['city' => 'makkah', 'name' => 'Swissôtel', 'distance_metres' => 150, 'nights' => 7, 'sort_order' => 1]);
        $this->departure->itinerary()->create(['day_number' => 1, 'city' => 'madinah', 'title' => ['en' => 'Arrive in Madinah', 'dv' => 'މަދީނާއަށް'], 'description' => ['en' => 'Rest.']]);
        $this->departure->flights()->create([
            'direction' => 'outbound', 'airline' => 'Maldivian', 'flight_number' => 'Q2 710',
            'from_airport' => 'MLE', 'to_airport' => 'MED', 'departs_at' => '2027-03-10 22:30:00', 'arrives_at' => '2027-03-11 03:15:00',
            'seats' => 40, 'booking_reference' => 'PNR123', 'notes' => 'Check in three hours before.',
        ]);
        $this->departure->transfers()->create([
            'starts_at' => '2027-03-11 05:00:00', 'mode' => 'coach', 'from_place' => 'MED airport', 'to_place' => 'Madinah hotel',
            'meeting_point' => 'Arrivals, gate 3', 'provider' => 'Al Mahdi Transport',
        ]);
        $item = $this->departure->checklist()->create(['title' => 'Send the rooming list to the hotel', 'due_on' => '2027-03-03', 'is_blocking' => true, 'sort_order' => 1]);
        $item->forceFill(['done_at' => now(), 'done_by' => User::factory()->create()->getKey()])->save();
        DepartureCost::query()->create([
            'departure_id' => $this->departure->getKey(), 'category' => 'hotel', 'supplier' => 'Swissôtel', 'description' => 'Makkah rooms',
            'currency' => 'SAR', 'amount_minor' => 4_500_000, 'is_per_person' => false, 'status' => DepartureCost::PAID, 'incurred_on' => '2027-02-15',
        ]);
        Booking::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'departure_id' => $this->departure->getKey(),
            'seats' => 2,
        ]);
    }

    public function test_the_copy_carries_the_set_up_with_every_date_moved(): void
    {
        $copy = app(DepartureCopier::class)->copy($this->departure, CarbonImmutable::parse('2028-02-28'));

        $this->assertSame($this->package->getKey(), $copy->package_id);
        $this->assertSame('2028-02-28', $copy->date_start->toDateString());
        $this->assertSame('2028-03-13', $copy->date_end->toDateString(), 'Fourteen nights stay fourteen nights.');
        $this->assertSame('Maldivian', $copy->airline);
        $this->assertSame(40, $copy->capacity_total);
        $this->assertSame(0, $copy->capacity_held, 'Seats held belong to the run that happened.');
        $this->assertSame(0, $copy->capacity_confirmed);
        $this->assertFalse($copy->is_published, 'A copy goes live when somebody has read it through.');
        $this->assertNull($copy->nusuk_accommodation_recorded_at, 'A Nusuk record is for one departure.');

        $this->assertCount(2, $copy->priceTiers);
        $this->assertSame(2_850_000, $copy->priceTiers->firstWhere('occupancy', 'double')->amount_minor);
        $this->assertSame('Swissôtel', $copy->hotels->sole()->name);
        $this->assertSame('މަދީނާއަށް', $copy->itinerary->sole()->getTranslation('title', 'dv'), 'Both languages come along.');

        $flight = $copy->flights->sole();
        $this->assertSame('2028-02-28 22:30:00', $flight->departs_at->format('Y-m-d H:i:s'));
        $this->assertSame('2028-02-29 03:15:00', $flight->arrives_at->format('Y-m-d H:i:s'));
        $this->assertNull($flight->booking_reference, 'A PNR is for one flight on one day.');

        $this->assertSame('2028-02-29 05:00:00', $copy->transfers->sole()->starts_at->format('Y-m-d H:i:s'));

        $checklist = $copy->checklist->sole();
        $this->assertSame('2028-02-21', $checklist->due_on->toDateString());
        $this->assertTrue($checklist->is_blocking);
        $this->assertNull($checklist->done_at, 'Ticks are not copied.');

        $cost = DepartureCost::query()->where('departure_id', $copy->getKey())->sole();
        $this->assertSame(DepartureCost::ESTIMATED, $cost->status, 'What last year cost is this year\'s estimate.');
        $this->assertNull($cost->incurred_on);
        $this->assertSame(4_500_000, $cost->amount_minor);

        $this->assertSame(0, $copy->bookings()->count());
        $this->assertSame(1, $this->departure->bookings()->count(), 'The original is untouched.');
        $this->assertSame(1, $this->departure->refresh()->priceTiers()->count() - 1, 'Nothing moved: the original keeps its two tiers.');
    }

    public function test_staff_copy_a_departure_from_the_package_page(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER));

        Livewire::test(DeparturesRelationManager::class, [
            'ownerRecord' => $this->package,
            'pageClass' => EditPackage::class,
        ])
            ->callTableAction('copy', $this->departure, data: ['date_start' => '2028-02-28'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, Departure::query()->count());
        $copy = Departure::query()->whereDate('date_start', '2028-02-28')->sole();
        $this->assertCount(2, $copy->priceTiers);
        $this->assertFalse($copy->is_published);
    }

    public function test_a_role_that_cannot_create_departures_sees_no_copy_button(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Access::REPORTING));

        Livewire::test(DeparturesRelationManager::class, [
            'ownerRecord' => $this->package,
            'pageClass' => EditPackage::class,
        ])->assertTableActionHidden('copy', $this->departure);
    }

    public function test_the_confirmation_says_what_will_be_copied(): void
    {
        $this->assertSame(
            ['2 prices', '1 hotels', '1 itinerary days', '1 flights', '1 transfers', '1 checklist items', '1 estimated costs'],
            DepartureCopier::carries($this->departure),
        );

        $bare = Departure::factory()->create(['package_id' => $this->package->getKey()]);
        $this->assertSame([], DepartureCopier::carries($bare));
    }
}
