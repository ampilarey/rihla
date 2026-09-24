<?php

namespace Tests\Feature;

use App\Models\BlockedDate;
use App\Models\Partner;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RoomType;
use App\Models\Stay;
use App\Support\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public Stays pages — §15.4 (Phase 9.4).
 *
 * Named for the reader's half of the domain. `StaysPagesTest` already
 * covers the Phase 8.2 placeholders and `StaysNavigationTest` the menu —
 * `AGENTS.md` records what happened the last time a second suite on one
 * domain took the obvious name and silently replaced nineteen tests.
 */
class StaysPublicPagesTest extends TestCase
{
    use RefreshDatabase;

    private function guesthouseIsOn(): void
    {
        Services::save(['stays_guesthouses' => Services::ON]);
    }

    private function property(array $attributes = []): Property
    {
        return Property::factory()->create([
            'island' => 'Maafushi',
            'currency' => 'USD',
            'min_nights' => 1,
            ...$attributes,
        ]);
    }

    // ── The hub ──────────────────────────────────────────────────────────

    public function test_the_hub_is_not_reachable_when_every_strand_is_off(): void
    {
        $this->get('/en/stays')->assertNotFound();
    }

    public function test_the_hub_lists_the_strands_that_are_not_off(): void
    {
        Services::save([
            'stays_guesthouses' => Services::ON,
            'stays_island_holidays' => Services::COMING_SOON,
            'stays_rooms' => Services::OFF,
        ]);

        $this->get('/en/stays')
            ->assertOk()
            ->assertSee('Guesthouses')
            ->assertSee('Island holidays')
            ->assertDontSee('Rooms in Malé');
    }

    // ── A strand with nothing published ──────────────────────────────────

    /**
     * §15.2 decision 6 — and an honest answer rather than a hidden feature.
     * A guesthouse line with nothing published is genuinely an enquiry
     * business, and the page that says so and takes a phone number is the
     * right one.
     */
    public function test_a_strand_with_nothing_published_takes_an_enquiry(): void
    {
        $this->guesthouseIsOn();

        $this->get('/en/stays/guesthouses')
            ->assertOk()
            ->assertSee('Or leave your details');
    }

    public function test_an_unpublished_property_does_not_make_a_listing(): void
    {
        $this->guesthouseIsOn();
        $this->property(['is_published' => false]);

        $this->get('/en/stays/guesthouses')
            ->assertOk()
            ->assertSee('Or leave your details');
    }

    // ── The listing ──────────────────────────────────────────────────────

    public function test_a_published_guesthouse_is_listed(): void
    {
        $this->guesthouseIsOn();
        $property = $this->property(['name' => ['en' => 'Maafushi View']]);

        $this->get('/en/stays/guesthouses')
            ->assertOk()
            ->assertSee('Maafushi View')
            ->assertSee($property->slug);
    }

    /** A Malé rental is a different strand and must not appear here. */
    public function test_a_rental_is_not_listed_under_guesthouses(): void
    {
        $this->guesthouseIsOn();
        $this->property(['name' => ['en' => 'Maafushi View']]);
        Property::factory()->rental()->create(['name' => ['en' => 'Malé Nightly Room']]);

        $this->get('/en/stays/guesthouses')
            ->assertOk()
            ->assertSee('Maafushi View')
            ->assertDontSee('Malé Nightly Room');
    }

    public function test_the_island_filter_narrows_the_list(): void
    {
        $this->guesthouseIsOn();
        $this->property(['name' => ['en' => 'Maafushi View'], 'island' => 'Maafushi']);
        $this->property(['name' => ['en' => 'Fulidhoo Sands'], 'island' => 'Fulidhoo']);

        $this->get('/en/stays/guesthouses?island=Fulidhoo')
            ->assertOk()
            ->assertSee('Fulidhoo Sands')
            ->assertDontSee('Maafushi View');
    }

    /** A hand-edited URL shows guesthouses, not a validation page. */
    public function test_a_nonsense_filter_is_ignored(): void
    {
        $this->guesthouseIsOn();
        $this->property(['name' => ['en' => 'Maafushi View']]);

        $this->get('/en/stays/guesthouses?from=not-a-date&to=nonsense&guests=abc')
            ->assertOk()
            ->assertSee('Maafushi View');
    }

    /**
     * Dates filter the list through the same Availability the booking path
     * uses. A faster query that eventually disagrees with the booking path
     * about whether somewhere is free is the one disagreement this line
     * cannot afford.
     */
    public function test_a_guesthouse_with_nothing_free_drops_out_for_those_dates(): void
    {
        $this->guesthouseIsOn();
        $property = $this->property(['name' => ['en' => 'Maafushi View']]);
        $room = RoomType::factory()->create(['property_id' => $property->id, 'quantity' => 1]);

        Stay::factory()->held()->create([
            'room_type_id' => $room->id,
            'property_id' => $property->id,
            'check_in' => '2027-03-03',
            'check_out' => '2027-03-05',
        ]);

        $this->get('/en/stays/guesthouses?from=2027-03-03&to=2027-03-05')
            ->assertOk()
            ->assertDontSee('Maafushi View');

        // And is back for dates nobody has taken.
        $this->get('/en/stays/guesthouses?from=2027-03-05&to=2027-03-07')
            ->assertOk()
            ->assertSee('Maafushi View');
    }

    // ── A property page ──────────────────────────────────────────────────

    public function test_a_published_property_has_a_page(): void
    {
        $this->guesthouseIsOn();
        $property = $this->property(['name' => ['en' => 'Maafushi View']]);
        RoomType::factory()->create([
            'property_id' => $property->id,
            'name' => ['en' => 'Sea View Double'],
            'base_rate_minor' => 8500,
        ]);

        $this->get('/en/stays/'.$property->slug)
            ->assertOk()
            ->assertSee('Maafushi View')
            ->assertSee('Sea View Double')
            ->assertSee('USD 85');
    }

    public function test_an_unpublished_property_has_no_page(): void
    {
        $this->guesthouseIsOn();
        $property = $this->property(['is_published' => false]);

        $this->get('/en/stays/'.$property->slug)->assertNotFound();
    }

    public function test_a_property_is_unreachable_when_its_service_is_off(): void
    {
        $property = $this->property();

        $this->get('/en/stays/'.$property->slug)->assertNotFound();
    }

    /** With dates, the page quotes the stay rather than a nightly rate. */
    public function test_the_page_totals_the_chosen_nights(): void
    {
        $this->guesthouseIsOn();
        $property = $this->property();
        $room = RoomType::factory()->create([
            'property_id' => $property->id,
            'name' => ['en' => 'Sea View Double'],
            'base_rate_minor' => 10000,
            'quantity' => 1,
        ]);

        Rate::factory()->create([
            'room_type_id' => $room->id,
            'starts_on' => '2027-03-04',
            'ends_on' => '2027-03-04',
            'rate_minor' => 25000,
        ]);

        $this->get('/en/stays/'.$property->slug.'?from=2027-03-03&to=2027-03-05')
            ->assertOk()
            ->assertSee('USD 350')
            ->assertSee('for 2 night');
    }

    public function test_a_room_that_is_taken_says_so_rather_than_quoting(): void
    {
        $this->guesthouseIsOn();
        $property = $this->property();
        $room = RoomType::factory()->create(['property_id' => $property->id, 'quantity' => 1]);

        BlockedDate::factory()->create(['room_type_id' => $room->id, 'date' => '2027-03-03']);

        $this->get('/en/stays/'.$property->slug.'?from=2027-03-03&to=2027-03-05')
            ->assertOk()
            ->assertSee('Not free for those dates');
    }

    /** The policy is in the reader's language and in plain numbers. */
    public function test_the_page_prints_the_policy_it_will_be_held_to(): void
    {
        $this->guesthouseIsOn();
        $partner = Partner::factory()->create(['green_tax_mode' => Partner::GREEN_TAX_AT_PROPERTY]);
        $property = $this->property([
            'partner_id' => $partner->id,
            'deposit_pct' => 40,
            'balance_days_before' => 21,
            'free_cancel_days' => 7,
        ]);

        $this->get('/en/stays/'.$property->slug)
            ->assertOk()
            ->assertSee('40% deposit')
            ->assertSee('21 days before')
            ->assertSee('7 days before')
            ->assertSee('Green tax is paid at the guesthouse');
    }

    // ── Three languages ──────────────────────────────────────────────────

    /**
     * §15.4: fall back to English **and say so**. Never a blank, never a
     * machine translation. The second half is the point — a page silently
     * in the wrong language reads as a site that does not care.
     */
    public function test_an_untranslated_property_says_it_is_in_english(): void
    {
        $this->guesthouseIsOn();
        $property = $this->property([
            'name' => ['en' => 'Maafushi View'],
            'summary' => ['en' => 'Two minutes from the jetty.'],
        ]);

        $this->get('/ar/stays/'.$property->slug)
            ->assertOk()
            ->assertSee('Two minutes from the jetty.')
            ->assertSee('shown in English');
    }

    public function test_a_translated_property_does_not_apologise(): void
    {
        $this->guesthouseIsOn();
        $property = $this->property([
            'name' => ['en' => 'Maafushi View', 'ar' => 'منظر ماافوشي'],
            'summary' => ['en' => 'Two minutes from the jetty.', 'ar' => 'دقيقتان من الرصيف.'],
        ]);

        $this->get('/ar/stays/'.$property->slug)
            ->assertOk()
            ->assertSee('دقيقتان من الرصيف.')
            ->assertDontSee('shown in English');
    }

    // ── The reserved slugs ───────────────────────────────────────────────

    /**
     * A property slugged `guesthouses` would be shadowed by the strand
     * route and simply never render — no error, nothing to notice. A
     * shared link that silently goes somewhere else is worse than one that
     * 404s, so the slug can never be minted in the first place.
     */
    public function test_a_property_cannot_take_a_slug_that_a_strand_route_owns(): void
    {
        foreach (Property::RESERVED_SLUGS as $reserved) {
            $property = Property::factory()->create([
                'slug' => null,
                'name' => ['en' => ucfirst(str_replace('-', ' ', $reserved))],
            ]);

            $this->assertNotSame($reserved, $property->slug, "A property took the reserved slug '{$reserved}'.");
        }
    }

    /** And the strand routes still answer for themselves. */
    public function test_the_strand_routes_are_not_shadowed(): void
    {
        $this->guesthouseIsOn();

        $this->get('/en/stays/guesthouses')->assertOk();
    }
}
