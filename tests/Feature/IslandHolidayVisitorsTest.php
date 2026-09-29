<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Departure;
use App\Models\HostPage;
use App\Models\Package;
use App\Models\PriceTier;
use App\Models\Property;
use App\Models\RoomType;
use App\Support\Services;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An island holiday sold to visitors too, and shown on the host's page —
 * §16.14, §16 Phase 15.
 */
class IslandHolidayVisitorsTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        Services::save(['stays_guesthouses' => Services::ON, 'stays_island_holidays' => Services::ON]);

        $this->property = Property::factory()->create(['name' => ['en' => 'Fulidhoo Garden']]);
        RoomType::factory()->create(['property_id' => $this->property->id]);
    }

    private function holiday(string $soldTo, array $attributes = []): Package
    {
        $package = Package::factory()->islandHoliday()->create($attributes + [
            'title' => ['en' => 'A Fulidhoo weekend'],
            'slug' => 'fulidhoo-weekend-'.(Package::max('id') + 1),
            'sold_to' => $soldTo,
            'property_id' => $this->property->id,
            'is_published' => true,
        ]);

        $departure = Departure::factory()->withSeats(10)->create([
            'package_id' => $package->id,
            'date_start' => now()->addDays(30),
            'date_end' => now()->addDays(32),
        ]);

        PriceTier::create(['departure_id' => $departure->id, 'occupancy' => 'double', 'amount_minor' => 300_000]);
        PriceTier::create(['departure_id' => $departure->id, 'audience' => 'tourist', 'occupancy' => 'double', 'amount_minor' => 25_000]);

        return $package->refresh();
    }

    /** @return array<string, mixed> */
    private function party(): array
    {
        return [
            'contact_name' => 'Sara Visitor',
            'contact_phone' => '+44 7700 900123',
            'travellers' => [['full_name' => 'Sara Visitor', 'date_of_birth' => now()->subYears(35)->toDateString()]],
        ];
    }

    private function bookAs(Package $package, ?string $audience): Booking
    {
        $this->post("/en/packages/{$package->slug}/book", array_filter([
            'departure' => $package->departures()->sole()->id,
            'occupancy' => 'double',
            'seats' => 1,
            'audience' => $audience,
        ]))->assertRedirect('/en/book/travellers');

        $this->post('/en/book/travellers', $this->party())->assertRedirect('/en/book/review');

        return Booking::latest('id')->firstOrFail();
    }

    public function test_a_visitor_books_at_the_visitor_price_in_the_visitors_currency(): void
    {
        $booking = $this->bookAs($this->holiday('both'), 'tourist');

        $this->assertSame('USD', $booking->currency);
        $this->assertSame(25_000, $booking->total_minor);
        $this->assertSame('USD', $booking->lines()->sole()->currency);
    }

    public function test_a_maldivian_books_the_same_package_at_the_local_price(): void
    {
        $booking = $this->bookAs($this->holiday('both'), 'local');

        $this->assertSame('MVR', $booking->currency);
        $this->assertSame(300_000, $booking->total_minor);
    }

    /** A package sold to Maldivians only never quotes the visitor price, whatever the request says. */
    public function test_a_local_only_package_ignores_a_request_for_the_visitor_price(): void
    {
        $booking = $this->bookAs($this->holiday('local'), 'tourist');

        $this->assertSame('MVR', $booking->currency);
        $this->assertSame(300_000, $booking->total_minor);
    }

    public function test_a_visitor_price_is_saved_in_the_visitors_currency(): void
    {
        $departure = Departure::factory()->create();
        $tier = PriceTier::create(['departure_id' => $departure->id, 'audience' => 'tourist', 'occupancy' => 'quad', 'amount_minor' => 9000, 'currency' => 'MVR']);

        $this->assertSame('USD', $tier->currency, 'Never dollars quoted as rufiyaa.');
        $this->assertSame('USD 90', $departure->refresh()->leadPriceFor('tourist')?->format());
    }

    public function test_the_lead_price_never_mixes_currencies(): void
    {
        $departure = $this->holiday('both')->departures()->sole();

        // USD 250 is a smaller number than MVR 3,000, and is still not the local "from" price.
        $this->assertSame('MVR', $departure->lead_price?->currency);
        $this->assertSame(300_000, $departure->lead_price?->minor);
    }

    public function test_a_departure_prices_the_same_room_on_both_lists(): void
    {
        $departure = Departure::factory()->create();
        PriceTier::create(['departure_id' => $departure->id, 'occupancy' => 'quad', 'amount_minor' => 1]);
        PriceTier::create(['departure_id' => $departure->id, 'audience' => 'tourist', 'occupancy' => 'quad', 'amount_minor' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);
        PriceTier::create(['departure_id' => $departure->id, 'audience' => 'tourist', 'occupancy' => 'quad', 'amount_minor' => 2]);
    }

    public function test_the_booking_page_asks_only_when_there_is_a_choice(): void
    {
        $this->get('/en/packages/'.$this->holiday('both')->slug.'/book')->assertOk()
            ->assertSee('Are you a Maldivian citizen?')
            ->assertSee('Visitors from USD 250');

        $this->get('/en/packages/'.$this->holiday('local')->slug.'/book')->assertOk()
            ->assertDontSee('Are you a Maldivian citizen?');
    }

    // ── On the host's page ───────────────────────────────────────────────

    public function test_the_host_page_shows_packages_at_this_guesthouse(): void
    {
        $host = $this->property->partner;
        HostPage::factory()->published()->create(['partner_id' => $host->id]);

        $this->holiday('both', ['title' => ['en' => 'A Fulidhoo weekend']]);
        $this->holiday('local', ['title' => ['en' => 'Draft island trip'], 'is_published' => false]);
        $elsewhere = Property::factory()->create();
        $this->holiday('local', ['title' => ['en' => 'Somebody else\'s island'], 'property_id' => $elsewhere->id]);

        $page = '/en/stays/hosts/'.$host->slug;

        $this->get($page)->assertOk()
            ->assertSee('Packages at this guesthouse')
            ->assertSee('A Fulidhoo weekend')
            ->assertSee('USD 250', false)
            ->assertDontSee('Draft island trip')
            ->assertDontSee('Somebody else&#039;s island', false);

        $this->get('/dv/stays/hosts/'.$host->slug)->assertOk()->assertSee('MVR 3,000');

        Services::save(['stays_guesthouses' => Services::ON, 'stays_island_holidays' => Services::OFF]);
        $this->get($page)->assertOk()->assertDontSee('Packages at this guesthouse');
    }
}
