<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\Package;
use App\Models\PriceTier;
use App\Models\WhyFeature;
use App\Models\WhySection;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The homepage sections added in Phase 2.5.
 *
 * Additive: the hero banners, the why-section and the trip sections are
 * untouched and still render. What is new is the part that does the selling
 * — three routes in, and the departures anyone can still join.
 */
class HomepageDeparturesTest extends TestCase
{
    use RefreshDatabase;

    private function departure(string $title, string $startsAt, ?int $quadMajor = 22_000, array $attributes = []): Departure
    {
        $package = Package::factory()->create(['title' => ['en' => $title]]);

        $departure = Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => $startsAt,
            'date_end' => Carbon::parse($startsAt)->addDays(10),
            ...$attributes,
        ]);

        if ($quadMajor !== null) {
            PriceTier::create([
                'departure_id' => $departure->id,
                'occupancy' => 'quad',
                'amount_minor' => Money::ofMajor($quadMajor)->minor,
            ]);
        }

        return $departure;
    }

    public function test_the_three_ways_in_are_offered(): void
    {
        $this->get('/en')
            ->assertOk()
            ->assertSee('View packages')
            ->assertSee('Learn about Umrah')
            ->assertSee('Talk to an advisor');
    }

    public function test_upcoming_departures_are_listed_with_price_and_seats(): void
    {
        $this->departure('Shawwal Umrah', now()->addDays(40)->toDateString(), 22_000, [
            'capacity_total' => 24,
            'capacity_confirmed' => 18,
        ]);

        $this->get('/en')
            ->assertOk()
            ->assertSee('Upcoming departures')
            ->assertSee('Shawwal Umrah')
            ->assertSee('MVR 22,000')
            ->assertSee('6 seats left');
    }

    public function test_only_the_three_soonest_are_shown(): void
    {
        foreach ([10, 20, 30, 40] as $index => $days) {
            $this->departure('Departure '.($index + 1), now()->addDays($days)->toDateString());
        }

        $response = $this->get('/en')->assertOk();

        $response->assertSee('Departure 1');
        $response->assertSee('Departure 3');
        $response->assertDontSee('Departure 4');
    }

    /** An empty rail is worse than no rail. */
    public function test_the_section_is_absent_when_nothing_is_upcoming(): void
    {
        $this->departure('Last season', now()->subMonths(2)->toDateString());

        $this->get('/en')
            ->assertOk()
            ->assertDontSee('Upcoming departures');
    }

    public function test_an_unpublished_package_is_not_advertised(): void
    {
        $departure = $this->departure('Hidden', now()->addDays(30)->toDateString());
        $departure->package->update(['is_published' => false]);

        $this->get('/en')->assertOk()->assertDontSee('Hidden');
    }

    public function test_a_departure_without_a_price_still_appears_without_inventing_one(): void
    {
        $this->departure('Priced later', now()->addDays(30)->toDateString(), quadMajor: null);

        $this->get('/en')
            ->assertOk()
            ->assertSee('Priced later')
            // "From" only appears beside a real price.
            ->assertDontSee('MVR 0');
    }

    // ── The journey timeline ─────────────────────────────────────────────

    /**
     * Written as the real journey, not as this website's checkout. There is
     * no online booking yet (Phase 3), and a timeline implying one would
     * promise a button that does not exist.
     */
    public function test_the_journey_names_the_visa_and_the_permit_separately(): void
    {
        // A traveller can hold a valid visa and still be barred from the
        // Mataf and the Rawdah without a Nusuk permit. One combined step
        // cannot represent that, which is why the plan makes them two
        // deliverables — and why the page names them separately.
        $this->get('/en')
            ->assertOk()
            ->assertSee('How the journey works')
            ->assertSee('Documents and visa')
            ->assertSee('Nusuk permit');
    }

    public function test_the_timeline_does_not_promise_online_payment(): void
    {
        $html = $this->get('/en')->assertOk()->getContent();

        foreach (['Pay online', 'Pay now', 'Book now', 'Checkout'] as $promise) {
            $this->assertStringNotContainsString(
                $promise,
                $html,
                "The homepage promises {$promise}, which no part of this site can do until Phase 3.",
            );
        }
    }

    // ── Still additive ───────────────────────────────────────────────────

    public function test_the_existing_homepage_sections_still_render(): void
    {
        // The why-section is database content, so it has to exist for this
        // to prove anything. The first version asserted the seeded heading
        // and failed on an empty test database — proving only that the
        // assertion was wrong.
        $section = WhySection::create([
            'title' => ['en' => 'Why Choose Rihla'],
            'subtitle' => ['en' => 'What a Maldivian group gets.'],
            'is_active' => true,
        ]);

        WhyFeature::create([
            'why_section_id' => $section->id,
            'title' => ['en' => 'Trusted guides'],
            'text' => ['en' => 'Dhivehi-speaking, and with the group throughout.'],
            'is_active' => true,
            'sort_order' => 0,
        ]);

        WhySection::forgetCache();

        $this->get('/en')
            ->assertOk()
            ->assertSee('Why Choose Rihla')
            ->assertSee('Trusted guides');

        $this->get('/dv')->assertOk();
    }
}
