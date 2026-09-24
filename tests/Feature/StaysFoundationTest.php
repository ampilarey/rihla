<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Partners, properties and room types — §15.4 (Phase 9.1).
 *
 * The data half. `StaysAdminTest` covers the screens, which is a separate
 * file on purpose: `AGENTS.md` records what happened the last time a second
 * suite on one domain reused the obvious name and silently replaced
 * nineteen tests.
 */
class StaysFoundationTest extends TestCase
{
    use RefreshDatabase;

    // ── What the schema refuses ──────────────────────────────────────────

    /**
     * A partner with a building on the books is not deletable.
     *
     * Restricted rather than cascading, for the reason the migration
     * records: losing a property because somebody tidied up a contact
     * record is not a recoverable mistake. `is_active` is how a partner
     * leaves.
     */
    public function test_a_partner_with_properties_cannot_be_deleted(): void
    {
        $partner = Partner::factory()->create();
        Property::factory()->create(['partner_id' => $partner->id]);

        $this->expectException(QueryException::class);

        $partner->delete();
    }

    public function test_a_partner_with_nothing_attached_can_be_deleted(): void
    {
        $partner = Partner::factory()->create();

        $partner->delete();

        $this->assertDatabaseMissing('partners', ['id' => $partner->id]);
    }

    /**
     * The other direction, and deliberately not the same answer: a room type
     * has no meaning apart from its building, so deleting the building takes
     * its rooms rather than refusing.
     */
    public function test_deleting_a_property_takes_its_room_types(): void
    {
        $property = Property::factory()->create();
        $room = RoomType::factory()->create(['property_id' => $property->id]);

        $property->delete();

        $this->assertDatabaseMissing('room_types', ['id' => $room->id]);
    }

    // ── The URL ──────────────────────────────────────────────────────────

    public function test_a_property_takes_its_slug_from_the_english_name(): void
    {
        $property = Property::create([
            'partner_id' => Partner::factory()->create()->id,
            'name' => ['en' => 'Maafushi View', 'ar' => 'ماافوشي'],
            'summary' => ['en' => 'A room by the jetty.'],
        ]);

        $this->assertSame('maafushi-view', $property->slug);
    }

    /**
     * English, not "whatever locale the staff member happened to be in".
     * The share kit is one URL per language over the same slug — a slug that
     * came out in Arabic for one property and English for the next is not a
     * link anybody can read back over WhatsApp.
     */
    public function test_the_slug_is_english_even_when_the_admin_is_reading_arabic(): void
    {
        app()->setLocale('ar');

        $property = Property::create([
            'partner_id' => Partner::factory()->create()->id,
            'name' => ['en' => 'Fulidhoo Sands', 'ar' => 'فُلِދޫ'],
            'summary' => ['en' => 'Two minutes from the harbour.'],
        ]);

        $this->assertSame('fulidhoo-sands', $property->slug);
    }

    public function test_an_explicit_slug_is_left_alone(): void
    {
        $property = Property::factory()->create(['slug' => 'the-one-already-shared']);

        $this->assertSame('the-one-already-shared', $property->slug);
    }

    // ── Three languages, with English underneath ─────────────────────────

    /**
     * §15.4 asks for a property to carry all three languages in one row, and
     * `AGENTS.md` forbids filling a gap with a machine translation. So the
     * gap falls back to English, which is the honest answer.
     */
    public function test_a_property_with_no_arabic_falls_back_to_english(): void
    {
        $property = Property::factory()->create([
            'name' => ['en' => 'Thulusdhoo Breeze'],
        ]);

        app()->setLocale('ar');

        $this->assertSame('Thulusdhoo Breeze', $property->name);
    }

    public function test_arabic_is_used_when_it_is_actually_there(): void
    {
        $property = Property::factory()->create([
            'name' => ['en' => 'Thulusdhoo Breeze', 'ar' => 'نسيم ثولوسدو'],
        ]);

        app()->setLocale('ar');

        $this->assertSame('نسيم ثولوسدو', $property->name);
    }

    /**
     * A translated attribute with nothing stored comes back as an empty
     * *string*, not an array — which is how a string once reached code that
     * foreach'd over it. The accessor absorbs that.
     */
    public function test_the_amenity_list_is_a_list_even_when_there_is_nothing_stored(): void
    {
        $property = Property::factory()->create(['amenities' => []]);

        app()->setLocale('ar');

        $this->assertSame([], $property->amenity_list);
    }

    // ── Money ────────────────────────────────────────────────────────────

    /**
     * Null rather than zero, for the reason the model records: "from USD 0"
     * is a worse card than a card with no price on it, and the difference
     * between "free" and "not priced yet" is not one a customer should have
     * to work out.
     */
    public function test_a_property_with_no_rooms_has_no_from_price(): void
    {
        $property = Property::factory()->create();

        $this->assertNull($property->cheapestRate());
    }

    public function test_the_from_price_is_the_cheapest_room(): void
    {
        $property = Property::factory()->create(['currency' => 'USD']);
        RoomType::factory()->create(['property_id' => $property->id, 'base_rate_minor' => 12000]);
        RoomType::factory()->create(['property_id' => $property->id, 'base_rate_minor' => 7500]);

        $rate = $property->fresh()->cheapestRate();

        $this->assertNotNull($rate);
        $this->assertSame(7500, $rate->minor);
        $this->assertSame('USD', $rate->currency);
    }

    /** An unpriced room is not the cheapest room; it is an unfinished one. */
    public function test_a_room_with_no_rate_yet_is_not_the_from_price(): void
    {
        $property = Property::factory()->create();
        RoomType::factory()->create(['property_id' => $property->id, 'base_rate_minor' => 0]);
        RoomType::factory()->create(['property_id' => $property->id, 'base_rate_minor' => 9000]);

        $this->assertSame(9000, $property->fresh()->cheapestRate()?->minor);
    }

    /** A stay is one payment, so the room takes the building's currency. */
    public function test_a_room_rate_is_quoted_in_the_property_currency(): void
    {
        $property = Property::factory()->rental()->create();
        $room = RoomType::factory()->create(['property_id' => $property->id, 'base_rate_minor' => 90000]);

        $this->assertSame('MVR', $room->fresh()->baseRate()->currency);
    }

    // ── What is bookable ─────────────────────────────────────────────────

    /**
     * §15.2 decision 1. Rihla does not own these buildings, and availability
     * a partner has not given in writing is not Rihla's to promise.
     */
    public function test_a_property_is_on_request_unless_somebody_says_otherwise(): void
    {
        $this->assertFalse(Property::factory()->create()->instant_book);
    }

    public function test_an_unpublished_property_is_not_instant_bookable_even_when_flagged(): void
    {
        $property = Property::factory()->instantBook()->unpublished()->create();

        $this->assertTrue($property->instant_book);
        $this->assertFalse($property->isInstantBookable());
    }

    public function test_a_published_instant_book_property_is_instant_bookable(): void
    {
        $this->assertTrue(Property::factory()->instantBook()->create()->isInstantBookable());
    }

    // ── The policy the customer is held to ───────────────────────────────

    /**
     * §15.2 decision 2's defaults land on the row, not in a constant.
     *
     * The distinction matters on the day the house standard changes: a
     * property sold under 30% keeps 30% because the number is stored,
     * where a constant would move and reprint a policy the customer never
     * agreed to. Same shape as the defect `AGENTS.md` records under
     * "a migration must never write a constant".
     */
    public function test_a_new_property_carries_the_house_policy(): void
    {
        $property = Property::factory()->create();

        $this->assertSame(30, $property->deposit_pct);
        $this->assertSame(14, $property->balance_days_before);
        $this->assertSame(14, $property->free_cancel_days);
    }

    /**
     * The model default and the column default have to agree.
     *
     * There are two of them because they answer different callers: the
     * `$attributes` default is what a Property just created reads in memory,
     * and the column default is what a row written outside Eloquent gets.
     * Neither is redundant and nothing makes them match — so this inserts
     * straight through the query builder, which the model never touches, and
     * compares the result to the constants the model carries.
     */
    public function test_the_column_default_and_the_model_default_are_the_same_policy(): void
    {
        $id = DB::table('properties')->insertGetId([
            'partner_id' => Partner::factory()->create()->id,
            'slug' => 'written-without-eloquent',
            'name' => json_encode(['en' => 'Written Without Eloquent']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('properties')->where('id', $id)->first();

        $this->assertSame(Property::DEFAULT_DEPOSIT_PCT, (int) $row->deposit_pct);
        $this->assertSame(Property::DEFAULT_BALANCE_DAYS_BEFORE, (int) $row->balance_days_before);
        $this->assertSame(Property::DEFAULT_FREE_CANCEL_DAYS, (int) $row->free_cancel_days);
    }

    public function test_a_property_can_be_sold_under_a_policy_of_its_own(): void
    {
        $property = Property::factory()->create([
            'deposit_pct' => 50,
            'balance_days_before' => 30,
            'free_cancel_days' => 7,
        ]);

        $this->assertSame(50, $property->fresh()->deposit_pct);
        $this->assertSame(30, $property->fresh()->balance_days_before);
        $this->assertSame(7, $property->fresh()->free_cancel_days);
    }

    // ── Scopes and partners ──────────────────────────────────────────────

    public function test_published_and_of_type_narrow_the_list(): void
    {
        $guesthouse = Property::factory()->create();
        Property::factory()->unpublished()->create();
        $rental = Property::factory()->rental()->create();

        $published = Property::published()->pluck('id');

        $this->assertTrue($published->contains($guesthouse->id));
        $this->assertTrue($published->contains($rental->id));
        $this->assertCount(2, $published);

        $this->assertSame(
            [$guesthouse->id],
            Property::published()->ofType([Property::GUESTHOUSE])->pluck('id')->all(),
        );
    }

    public function test_a_partner_says_how_rihla_is_paid(): void
    {
        $this->assertFalse(Partner::factory()->create()->isCommissionBased());
        $this->assertTrue(Partner::factory()->commissionBased(12)->create()->isCommissionBased());
    }

    public function test_an_inactive_partner_is_out_of_the_active_scope(): void
    {
        $working = Partner::factory()->create();
        Partner::factory()->inactive()->create();

        $this->assertSame([$working->id], Partner::active()->pluck('id')->all());
    }
}
