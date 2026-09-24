<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\Stays\GreenTax;
use App\Services\Stays\StayBooking;
use App\Support\Services;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The green tax says what it costs — §15.2 decision 5.
 *
 * `partners.green_tax_mode` has existed since §15.4 and said whether Rihla
 * collects the tax. Nothing said **how much**, so a guest read "paid at the
 * guesthouse", agreed, and met the figure at check-out — the precise
 * failure the mode was introduced to prevent. And `included` was stored and
 * acted on nowhere, so both modes printed an identical page.
 *
 * The rule these tests exist to hold: **an unset amount is not zero.** It
 * means nobody has told this system the figure, and a quote nobody checked
 * is the defect this codebase keeps catching in other forms.
 */
class GreenTaxTest extends TestCase
{
    use RefreshDatabase;

    /** USD 6.00 per guest per night, as a stand-in the owner would set. */
    private const STATED = 600;

    protected function setUp(): void
    {
        parent::setUp();

        Services::save(['stays_guesthouses' => Services::ON]);

        // The locale segment is normally supplied by SetLocale on a real
        // request; the template tests below render the view directly.
        URL::defaults(['locale' => 'en']);
    }

    private function property(string $mode = Partner::GREEN_TAX_AT_PROPERTY): Property
    {
        $partner = Partner::factory()->create(['green_tax_mode' => $mode]);

        return Property::factory()->create([
            'partner_id' => $partner->getKey(),
            'name' => ['en' => 'Maafushi View'],
            'currency' => 'USD',
            'min_nights' => 1,
        ]);
    }

    // ── The arithmetic ───────────────────────────────────────────────────

    public function test_it_is_per_guest_per_night(): void
    {
        config(['stays.green_tax.amount_minor' => self::STATED]);

        // Four people, five nights — twenty guest-nights at USD 6.
        $this->assertSame(12_000, app(GreenTax::class)->forParty(4, 5)?->minor);
        // `Money::format()` drops the decimals on a whole number of major
        // units — the convention the rest of the site prices in.
        $this->assertSame('USD 120', app(GreenTax::class)->forParty(4, 5)?->format());
    }

    public function test_the_rate_is_read_from_config(): void
    {
        config(['stays.green_tax.amount_minor' => 1_200, 'stays.green_tax.currency' => 'USD']);

        $rate = app(GreenTax::class)->perGuestPerNight();

        $this->assertSame(1_200, $rate?->minor);
        $this->assertSame('USD', $rate?->currency);
    }

    // ── An unset amount is not zero ──────────────────────────────────────

    /**
     * The whole point. Rendering an unstated tax as "USD 0.00" would put a
     * figure on a customer's quote that nobody at Rihla had checked.
     */
    public function test_an_unstated_amount_is_null_and_never_zero(): void
    {
        config(['stays.green_tax.amount_minor' => null]);

        $tax = app(GreenTax::class);

        $this->assertNull($tax->perGuestPerNight());
        $this->assertNull($tax->forParty(4, 5));
    }

    public function test_a_party_of_nobody_or_no_nights_is_null(): void
    {
        config(['stays.green_tax.amount_minor' => self::STATED]);

        $this->assertNull(app(GreenTax::class)->forParty(0, 5));
        $this->assertNull(app(GreenTax::class)->forParty(4, 0));
    }

    // ── What the page tells a reader ─────────────────────────────────────

    public function test_the_page_names_the_amount_when_it_is_known(): void
    {
        config(['stays.green_tax.amount_minor' => self::STATED]);
        $property = $this->property();

        $this->get('/en/stays/'.$property->slug)
            ->assertOk()
            ->assertSee('Green tax is paid at the guesthouse, not here.')
            ->assertSee('USD 6 per guest per night.');
    }

    /** Saying the tax applies is true and useless; a made-up figure is worse. */
    public function test_the_page_quotes_no_figure_it_has_not_been_given(): void
    {
        config(['stays.green_tax.amount_minor' => null]);
        $property = $this->property();

        $html = (string) $this->get('/en/stays/'.$property->slug)->assertOk()->getContent();

        $this->assertStringContainsString('Green tax is paid at the guesthouse, not here.', $html);
        $this->assertStringNotContainsString('per guest per night', $html);
        // A zero would render as 'USD 0' under this formatter, not 'USD 0.00'.
        $this->assertStringNotContainsString('USD 0', $html);
    }

    /**
     * The estimate needs both halves. Computed from a default party size it
     * would be a number nobody asked for, shown as though they had.
     */
    public function test_the_estimate_appears_only_once_dates_and_guests_are_chosen(): void
    {
        config(['stays.green_tax.amount_minor' => self::STATED]);
        $property = $this->property();
        RoomType::factory()->create(['property_id' => $property->id, 'base_rate_minor' => 10_000, 'quantity' => 2]);

        $bare = (string) $this->get('/en/stays/'.$property->slug)->assertOk()->getContent();
        $this->assertStringNotContainsString('For your dates and party', $bare);

        $withDates = (string) $this->get('/en/stays/'.$property->slug.'?'.http_build_query([
            // `from` and `to` — the names StayFilters actually reads.
            'from' => CarbonImmutable::now()->addDays(30)->toDateString(),
            'to' => CarbonImmutable::now()->addDays(33)->toDateString(),
            'guests' => 4,
        ]))->assertOk()->getContent();

        // Four guests, three nights, USD 6 — USD 72.00.
        $this->assertStringContainsString('For your dates and party', $withDates);
        $this->assertStringContainsString('USD 72', $withDates);
    }

    /** `included` printed the identical page before this. It no longer does. */
    public function test_the_two_modes_no_longer_read_the_same(): void
    {
        config(['stays.green_tax.amount_minor' => self::STATED]);

        $atProperty = (string) $this->get('/en/stays/'.$this->property()->slug)->assertOk()->getContent();
        $included = (string) $this->get('/en/stays/'.$this->property(Partner::GREEN_TAX_INCLUDED)->slug)->assertOk()->getContent();

        $this->assertStringContainsString('paid at the guesthouse', $atProperty);
        $this->assertStringNotContainsString('already included', $atProperty);

        $this->assertStringContainsString('already included', $included);
        $this->assertStringNotContainsString('paid at the guesthouse', $included);
    }

    // ── Frozen, like everything else on the quote ────────────────────────

    /**
     * The amount is a config value a government moves. A customer is owed
     * the figure they were shown on the day — the same rule the nightly
     * rates and the cancellation policy are held to.
     */
    public function test_the_amount_is_frozen_into_the_snapshot(): void
    {
        config(['stays.green_tax.amount_minor' => self::STATED]);

        $property = $this->property();
        $room = RoomType::factory()->create([
            'property_id' => $property->id, 'base_rate_minor' => 10_000, 'quantity' => 2,
        ]);

        $stay = app(StayBooking::class)->request(
            Customer::factory()->create(),
            $room,
            CarbonImmutable::parse('2027-03-03'),
            CarbonImmutable::parse('2027-03-06'),
            adults: 2,
            children: 2,
        );

        $frozen = $stay->rate_snapshot['green_tax'];

        // Four guests, three nights.
        $this->assertSame(600, $frozen['per_guest_per_night_minor']);
        $this->assertSame(7_200, $frozen['total_minor']);
        $this->assertSame(4, $frozen['guests']);
        $this->assertSame(Partner::GREEN_TAX_AT_PROPERTY, $frozen['mode']);

        // The government moves it. The agreed stay does not.
        config(['stays.green_tax.amount_minor' => 1_200]);

        $this->assertSame(600, $stay->fresh()->rate_snapshot['green_tax']['per_guest_per_night_minor']);
        $this->assertSame(7_200, $stay->fresh()->rate_snapshot['green_tax']['total_minor']);
    }

    /** A stay agreed before anybody stated the amount records that, not zero. */
    public function test_a_snapshot_taken_with_no_stated_amount_records_null(): void
    {
        config(['stays.green_tax.amount_minor' => null]);

        $property = $this->property();
        $room = RoomType::factory()->create([
            'property_id' => $property->id, 'base_rate_minor' => 10_000, 'quantity' => 2,
        ]);

        $stay = app(StayBooking::class)->request(
            Customer::factory()->create(),
            $room,
            CarbonImmutable::parse('2027-04-03'),
            CarbonImmutable::parse('2027-04-05'),
            adults: 2,
        );

        $frozen = $stay->rate_snapshot['green_tax'];

        $this->assertNull($frozen['per_guest_per_night_minor']);
        $this->assertNull($frozen['total_minor']);
        $this->assertNotSame(0, $frozen['total_minor']);
    }

    /** The tax is never Rihla's money, in either mode. */
    public function test_it_does_not_move_what_rihla_charges(): void
    {
        $property = $this->property();
        $room = RoomType::factory()->create([
            'property_id' => $property->id, 'base_rate_minor' => 10_000, 'quantity' => 2,
        ]);

        config(['stays.green_tax.amount_minor' => null]);
        $without = app(StayBooking::class)->request(
            Customer::factory()->create(), $room,
            CarbonImmutable::parse('2027-05-03'), CarbonImmutable::parse('2027-05-05'), adults: 2,
        );

        config(['stays.green_tax.amount_minor' => self::STATED]);
        $with = app(StayBooking::class)->request(
            Customer::factory()->create(), $room->fresh(),
            CarbonImmutable::parse('2027-06-03'), CarbonImmutable::parse('2027-06-05'), adults: 2,
        );

        $this->assertSame($without->total_minor, $with->total_minor);
        $this->assertSame($without->deposit_minor, $with->deposit_minor);
        $this->assertSame(Stay::REQUESTED, $with->status);
    }

    // ── The artefact that gets forwarded ─────────────────────────────────

    /**
     * The fact sheet is what somebody sends to whoever is actually paying
     * — §15.4's whole reason for existing. A tax that is on the page and
     * not on the sheet is a surprise for exactly the person least able to
     * have read the page.
     *
     * The rate and not a total: the sheet has no party and no dates.
     *
     * Asserted against the **rendered template** rather than the PDF
     * bytes. dompdf typesets whatever HTML it is handed, so the template
     * is where this logic lives and where a regression would be; picking
     * words back out of a compressed content stream would test the
     * typesetter. `StayShareKitTest` already covers that the route
     * returns a real PDF, and the words below were confirmed in one with
     * `pdftotext` before this was written.
     */
    public function test_the_fact_sheet_carries_the_rate(): void
    {
        config(['stays.green_tax.amount_minor' => self::STATED]);

        $html = $this->sheetFor($this->property());

        $this->assertStringContainsString('Green tax is paid at the guesthouse', $html);
        $this->assertStringContainsString('USD 6 per guest per night', $html);
    }

    public function test_the_fact_sheet_quotes_no_figure_it_has_not_been_given(): void
    {
        config(['stays.green_tax.amount_minor' => null]);

        $html = $this->sheetFor($this->property());

        $this->assertStringContainsString('Green tax is paid at the guesthouse', $html);
        $this->assertStringNotContainsString('per guest per night', $html);
    }

    public function test_the_fact_sheet_says_when_it_is_included(): void
    {
        config(['stays.green_tax.amount_minor' => self::STATED]);

        $html = $this->sheetFor($this->property(Partner::GREEN_TAX_INCLUDED));

        $this->assertStringContainsString('already included', $html);
        $this->assertStringNotContainsString('paid at the guesthouse', $html);
    }

    /** The sheet template, with exactly what the controller hands it. */
    private function sheetFor(Property $property): string
    {
        RoomType::factory()->create([
            'property_id' => $property->id, 'base_rate_minor' => 10_000, 'quantity' => 2,
        ]);

        $tax = app(GreenTax::class);

        return view('pdf.property', [
            'property' => $property->fresh()->load(['roomTypes', 'partner']),
            'rooms' => $property->roomTypes,
            'locale' => 'en',
            'url' => route('stays.show', ['property' => $property->slug]),
            'cover' => null,
            'issuer' => ['name' => 'Rihla Travels', 'registration' => 'C11452023', 'phone' => '+960 777 1234'],
            'greenTaxAtProperty' => $tax->isCollectedAtProperty($property),
            'greenTaxRate' => $tax->perGuestPerNight(),
        ])->render();
    }
}
