<?php

namespace Tests\Feature;

use App\Exceptions\NotAnUmrahBooking;
use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Package;
use App\Models\Property;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Nusuk\PermitDesk;
use App\Services\Visa\VisaDesk;
use App\Support\Access;
use App\Support\Services;
use App\Support\TravelReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Island holidays — §15.5 (Phase 10).
 *
 * The Umrah package engine wearing a different shirt, and filed under
 * Stays because that is the owner's correction in §15.1: *"holiday
 * packages for locals to local islands in the guesthouses part."*
 *
 * The thing most worth guarding is the one sentence the plan is emphatic
 * about: **a family going to Ukulhas is never asked for a permit.** Asking
 * is not a harmless extra field — it is a form somebody abandons, and a
 * readiness board reading "not ready" for a family who are entirely ready
 * to go.
 */
class IslandHolidayTest extends TestCase
{
    use RefreshDatabase;

    private function bookingFor(Package $package): array
    {
        $departure = Departure::factory()->withSeats(10)->create([
            'package_id' => $package->getKey(),
            'is_published' => true,
        ]);

        $booking = Booking::factory()->create(['departure_id' => $departure->getKey()]);
        $traveller = Traveller::factory()->create(['customer_id' => $booking->customer_id]);

        return [$booking->fresh(), $traveller];
    }

    // ── Nothing is asked of a government ─────────────────────────────────

    public function test_an_island_holiday_needs_no_travel_documents(): void
    {
        $this->assertFalse(Package::factory()->islandHoliday()->create()->needsTravelDocuments());
        $this->assertTrue(Package::factory()->create()->needsTravelDocuments());
        $this->assertTrue(Package::factory()->create(['type' => Package::UMRAH_PLUS])->needsTravelDocuments());
    }

    public function test_a_family_on_an_island_holiday_is_asked_for_nothing(): void
    {
        [$booking, $traveller] = $this->bookingFor(Package::factory()->islandHoliday()->create());

        $this->assertSame([], TravelReadiness::requiredFor($booking));
        $this->assertSame([], TravelReadiness::forTraveller($booking, $traveller));
    }

    /**
     * And are therefore ready. An empty requirement list means nothing
     * stands between them and the ferry — not that readiness is unknown.
     */
    public function test_a_family_on_an_island_holiday_is_ready_to_go(): void
    {
        [$booking, $traveller] = $this->bookingFor(Package::factory()->islandHoliday()->create());

        $this->assertTrue(TravelReadiness::isReady($booking, $traveller));
    }

    /** A pilgrim is still asked for all three. */
    public function test_a_pilgrim_is_still_asked_for_passport_visa_and_permit(): void
    {
        [$booking, $traveller] = $this->bookingFor(Package::factory()->create());

        $this->assertSame(TravelReadiness::REQUIREMENTS, TravelReadiness::requiredFor($booking));
        $this->assertFalse(TravelReadiness::isReady($booking, $traveller));
    }

    /**
     * A booking whose package has gone is a broken row, and the safe
     * reading of a broken row is the strict one: better to ask a family
     * for a passport they do not need than to send a pilgrim to Jeddah
     * without a visa.
     */
    public function test_a_booking_with_no_package_is_read_strictly(): void
    {
        $booking = Booking::factory()->create();
        $booking->setRelation('departure', null);

        $this->assertSame(TravelReadiness::REQUIREMENTS, TravelReadiness::requiredFor($booking));
    }

    // ── The desks refuse, loudly ─────────────────────────────────────────

    /**
     * Loud rather than a quiet null. A desk that silently declines to open
     * an application looks exactly like one that opened it, and the
     * traveller finds out at the point where somebody expected a visa to
     * exist.
     */
    public function test_the_visa_desk_refuses_an_island_holiday(): void
    {
        [$booking, $traveller] = $this->bookingFor(Package::factory()->islandHoliday()->create());

        $this->expectException(NotAnUmrahBooking::class);
        $this->expectExceptionMessageMatches('/island holiday/');

        app(VisaDesk::class)->open($booking, $traveller);
    }

    public function test_the_permit_desk_refuses_an_island_holiday(): void
    {
        [$booking, $traveller] = $this->bookingFor(Package::factory()->islandHoliday()->create());

        $this->expectException(NotAnUmrahBooking::class);
        $this->expectExceptionMessageMatches('/Umrah permit/');

        app(PermitDesk::class)->open($booking, $traveller);
    }

    public function test_the_desks_still_open_for_an_umrah(): void
    {
        [$booking, $traveller] = $this->bookingFor(Package::factory()->create());

        $this->assertNotNull(app(VisaDesk::class)->open($booking, $traveller));
        $this->assertNotNull(app(PermitDesk::class)->open($booking, $traveller));
    }

    // ── Who it is for ────────────────────────────────────────────────────

    /**
     * Derived from the type, never stored. A second column stating what
     * the first already states is a second copy to go stale — the shape
     * `AGENTS.md` records for a palette with more than one source of truth.
     */
    public function test_the_audience_follows_from_the_type(): void
    {
        $this->assertSame(Package::LOCALS, Package::factory()->islandHoliday()->create()->audience());
        $this->assertSame(Package::PILGRIMS, Package::factory()->create()->audience());
        $this->assertSame(
            Package::PILGRIMS,
            Package::factory()->create(['type' => Package::UMRAH_PLUS])->audience(),
        );
    }

    // ── Built on a guesthouse ────────────────────────────────────────────

    public function test_an_island_holiday_may_be_built_on_a_guesthouse(): void
    {
        $property = Property::factory()->create(['island' => 'Fulidhoo']);

        $holiday = Package::factory()->islandHoliday()->create(['property_id' => $property->id]);

        $this->assertSame($property->id, $holiday->property?->id);
        $this->assertSame('Fulidhoo', $holiday->property?->island);
    }

    /** Most packages have no property, and that is not an error. */
    public function test_a_package_without_a_property_is_fine(): void
    {
        $this->assertNull(Package::factory()->create()->property);
    }

    /** Written through the guarded path — the $fillable trap AGENTS.md names. */
    public function test_the_new_columns_are_writable_through_the_guarded_path(): void
    {
        $property = Property::factory()->create();

        $holiday = new Package;
        $holiday->fill([
            'slug' => 'fulidhoo-weekend',
            'type' => Package::ISLAND_HOLIDAY,
            'property_id' => $property->id,
            'title' => ['en' => 'Fulidhoo Weekend'],
            'summary' => ['en' => 'Two nights.'],
            'flexible_dates' => true,
            'min_nights' => 2,
        ]);
        $holiday->save();

        $holiday->refresh();

        $this->assertSame($property->id, $holiday->property_id);
        $this->assertTrue($holiday->isFlexible());
        $this->assertSame(2, $holiday->minimumNights());
    }

    /** A flexible package with no minimum still cannot be booked for nothing. */
    public function test_a_flexible_package_has_a_minimum_of_at_least_one_night(): void
    {
        $holiday = Package::factory()->islandHoliday()->create([
            'flexible_dates' => true,
            'min_nights' => null,
        ]);

        $this->assertSame(1, $holiday->minimumNights());
    }

    /**
     * Through the Filament form, not the model.
     *
     * `Factory::make()` runs unguarded, so a column missing from
     * `$fillable` is set happily in every other test here and dropped
     * silently by the admin form — the trap `AGENTS.md` names. The test
     * above writes through `fill()`; this one goes through the screen a
     * member of staff actually uses.
     */
    public function test_the_admin_form_saves_an_island_holiday(): void
    {
        $property = Property::factory()->create();
        $user = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        Livewire::actingAs($user)
            ->test(CreatePackage::class)
            ->fillForm([
                'slug' => 'fulidhoo-weekend',
                'title' => ['en' => 'Fulidhoo Weekend'],
                'summary' => ['en' => 'Two nights on Fulidhoo.'],
                'type' => Package::ISLAND_HOLIDAY,
                'property_id' => $property->id,
                'flexible_dates' => true,
                'min_nights' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $holiday = Package::where('slug', 'fulidhoo-weekend')->firstOrFail();

        $this->assertSame(Package::ISLAND_HOLIDAY, $holiday->type);
        $this->assertSame($property->id, $holiday->property_id);
        $this->assertTrue($holiday->isFlexible());
        $this->assertSame(2, $holiday->minimumNights());
        $this->assertFalse($holiday->needsTravelDocuments());
    }

    // ── Where it appears ─────────────────────────────────────────────────

    public function test_island_holidays_are_listed_under_stays(): void
    {
        Services::save(['stays_island_holidays' => Services::ON]);

        Package::factory()->islandHoliday()->create([
            'title' => ['en' => 'Fulidhoo Weekend'],
            'is_published' => true,
        ]);

        $this->get('/en/stays/island-holidays')
            ->assertOk()
            ->assertSee('Fulidhoo Weekend')
            ->assertSee('No passport, visa or permit');
    }

    /** An Umrah is not an island holiday and must not appear here. */
    public function test_an_umrah_is_not_listed_under_island_holidays(): void
    {
        Services::save(['stays_island_holidays' => Services::ON]);

        Package::factory()->islandHoliday()->create(['title' => ['en' => 'Fulidhoo Weekend'], 'is_published' => true]);
        Package::factory()->create(['title' => ['en' => 'Ramadan Umrah Fourteen'], 'is_published' => true]);

        $this->get('/en/stays/island-holidays')
            ->assertOk()
            ->assertSee('Fulidhoo Weekend')
            ->assertDontSee('Ramadan Umrah Fourteen');
    }

    /** Nothing published: the enquiry page, not an empty grid. */
    public function test_nothing_published_shows_the_enquiry_page(): void
    {
        Services::save(['stays_island_holidays' => Services::ON]);

        $this->get('/en/stays/island-holidays')
            ->assertOk()
            ->assertSee('Or leave your details');
    }
}
