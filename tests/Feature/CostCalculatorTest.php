<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\Package;
use App\Models\PriceTier;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * "What will this actually cost us?" — for a party, not one person.
 *
 * Umrah is booked by families, and two adults in a double is a different
 * number from four in a quad. The arithmetic runs in the browser from prices
 * the server rendered, so these tests cover what the server sends and what it
 * refuses to claim; the sums themselves were driven in a real browser
 * (1 quad = MVR 22,000, 4 quad = MVR 88,000, 4 single = MVR 164,000).
 */
class CostCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function departureWithPrices(array $prices = ['quad' => 22_000, 'single' => 41_000]): Departure
    {
        $package = Package::factory()->create(['title' => ['en' => 'Shawwal Umrah']]);

        $departure = Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => now()->addDays(40),
            'date_end' => now()->addDays(50),
        ]);

        foreach ($prices as $occupancy => $major) {
            PriceTier::create([
                'departure_id' => $departure->id,
                'occupancy' => $occupancy,
                'amount_minor' => Money::ofMajor($major)->minor,
            ]);
        }

        return $departure;
    }

    public function test_the_calculator_offers_every_room_that_is_priced(): void
    {
        $departure = $this->departureWithPrices();

        $this->get('/en/packages/'.$departure->package->slug)
            ->assertOk()
            ->assertSee('What will it cost?')
            ->assertSee('Quad room')
            ->assertSee('Single room')
            ->assertSee('Travellers');
    }

    public function test_no_calculator_is_shown_when_nothing_is_priced(): void
    {
        $package = Package::factory()->create();
        Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => now()->addDays(40),
            'date_end' => now()->addDays(50),
        ]);

        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertDontSee('What will it cost?');
    }

    /**
     * The browser does the arithmetic on minor units, so the amounts have to
     * reach it in minor units. If these ever ship as whole rufiyaa the totals
     * come out a hundred times too small.
     */
    public function test_prices_reach_the_browser_in_minor_units(): void
    {
        $departure = $this->departureWithPrices(['quad' => 22_000]);

        $html = $this->get('/en/packages/'.$departure->package->slug)->assertOk()->getContent();

        $this->assertStringContainsString('2200000', $html);
    }

    public function test_the_whatsapp_link_carries_the_package_and_the_date(): void
    {
        $departure = $this->departureWithPrices();

        $html = $this->get('/en/packages/'.$departure->package->slug)->assertOk()->getContent();

        $this->assertStringContainsString('wa.me/', $html);
        $this->assertStringContainsString(rawurlencode('Shawwal Umrah'), $html);
        $this->assertStringContainsString(rawurlencode($departure->date_start->format('j M Y')), $html);
    }

    /**
     * Instalment terms are business policy nobody has stated — whether the
     * deposit is a percentage or a flat sum, how many instalments, when they
     * fall due. Printing an invented schedule next to a real price is how
     * this site ended up advertising four social accounts that did not exist
     * and a playlist that returned 404.
     *
     * The total is arithmetic and is shown. The schedule is policy and is
     * asked for. This test fails if a plausible-looking one appears.
     */
    public function test_no_instalment_schedule_is_invented(): void
    {
        $departure = $this->departureWithPrices();

        $response = $this->get('/en/packages/'.$departure->package->slug)->assertOk();

        $response->assertSee('Ask us about paying in instalments.');

        $markup = (string) File::get(resource_path('views/components/cost-calculator.blade.php'));

        foreach (['deposit', 'instalmentCount', 'monthly', '% upfront'] as $invented) {
            $this->assertStringNotContainsStringIgnoringCase(
                $invented,
                preg_replace('/\{\{--.*?--\}\}/s', '', $markup) ?? '',
                "The calculator states an instalment term ({$invented}) that nobody has given us.",
            );
        }
    }
}
