<?php

namespace Tests\Feature;

use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The copyright line covers the years the company has existed, not just today.
 *
 * It read "© 2026 Rihla Travels" — the current year alone, on a business
 * registered in 2023, whose registration number ends in that year and is
 * printed four lines above it. Read together, the footer said the company was
 * registered three years ago and the site appeared this January.
 *
 * The start year is a literal in `Seo::FOUNDED` because it records something
 * that happened. The end year is the clock, so it advances with no help.
 */
class FooterCopyrightTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_footer_shows_the_span_and_not_just_this_year(): void
    {
        $response = $this->get('/en')->assertOk();

        // The entity, not the character: the Blade writes `&copy;`, so an
        // assertion spelled '©' matches nothing — which would have made the
        // negative below pass no matter what the footer said.
        $response->assertSee('&copy; '.Seo::FOUNDED.'–'.now()->year, false);

        $response->assertDontSee('&copy; '.now()->year.' Rihla Travels', false);
    }

    /**
     * `Seo::copyrightYears()` reads `now()` rather than `date()` for this.
     *
     * PHP's own clock ignores Carbon's test time, so written the obvious way
     * this branch could not have been asserted until the year it stopped
     * mattering.
     */
    public function test_the_span_collapses_in_the_founding_year(): void
    {
        Carbon::setTestNow(Carbon::create(Seo::FOUNDED, 6, 1));

        $this->assertSame((string) Seo::FOUNDED, Seo::copyrightYears(),
            'In the founding year the line should read one year, not "2023–2023".');

        Carbon::setTestNow();
    }

    public function test_the_span_ends_at_the_current_year(): void
    {
        Carbon::setTestNow(Carbon::create(2031, 2, 2));

        $this->assertSame(Seo::FOUNDED.'–2031', Seo::copyrightYears(),
            'The end of the range is the clock and should need no edit to advance.');

        Carbon::setTestNow();
    }

    /** The founding year is a fact about the past and may not drift. */
    public function test_the_founding_year_matches_the_registration_number(): void
    {
        $this->assertStringEndsWith((string) Seo::FOUNDED, Seo::REGISTRATION_NUMBER,
            'The registration number no longer ends in the founding year; one of the two has moved '
            .'and the footer is now claiming something the number beside it contradicts.');
    }
}
