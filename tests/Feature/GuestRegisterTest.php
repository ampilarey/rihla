<?php

namespace Tests\Feature;

use App\Casts\EncryptedIdentifier;
use App\Exceptions\RoomNotAvailable;
use App\Models\Customer;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayGuest;
use App\Services\Stays\StayBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The guest register, and rooms in Malé — §15.6 (Phase 11).
 *
 * Maldivian law requires a register of who slept where, so this holds a
 * name and a government identifier for people who are not even Rihla's
 * customers: one person books a room for four, and the other three never
 * agreed to anything. That is the whole reason the identifier is
 * encrypted and the whole reason it is scrubbed.
 */
class GuestRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function stay(): Stay
    {
        $property = Property::factory()->create(['currency' => 'MVR', 'min_nights' => 1]);
        $room = RoomType::factory()->create(['property_id' => $property->id, 'quantity' => 1]);

        return Stay::factory()->create([
            'room_type_id' => $room->id,
            'property_id' => $property->id,
        ]);
    }

    // ── The identifier ───────────────────────────────────────────────────

    public function test_an_identifier_round_trips(): void
    {
        $guest = StayGuest::factory()->create([
            'stay_id' => $this->stay()->id,
            'id_number' => 'P1234567',
        ]);

        $this->assertSame('P1234567', $guest->fresh()->id_number);
    }

    /** What is actually in the column is not the number. */
    public function test_the_column_holds_ciphertext_not_the_number(): void
    {
        $guest = StayGuest::factory()->create([
            'stay_id' => $this->stay()->id,
            'id_number' => 'P1234567',
        ]);

        $stored = DB::table('stay_guests')->where('id', $guest->id)->value('id_number');

        $this->assertNotSame('P1234567', $stored);
        $this->assertStringNotContainsString('P1234567', (string) $stored);
        $this->assertStringStartsWith(EncryptedIdentifier::MAGIC, (string) $stored);
    }

    /**
     * **The reason this is a custom cast rather than Laravel's own.**
     *
     * `data:anonymise` and `data:forget` write their stand-ins through the
     * query builder, not through Eloquent. A strict `encrypted` cast would
     * decrypt perfectly until the day somebody scrubbed the test server,
     * and every read afterwards would throw — on the one command whose
     * entire job is keeping real ID numbers off a public host.
     */
    public function test_a_plaintext_stand_in_written_straight_to_the_column_still_reads(): void
    {
        $guest = StayGuest::factory()->create(['stay_id' => $this->stay()->id]);

        DB::table('stay_guests')->where('id', $guest->id)
            ->update(['id_number' => 'Placeholder Person 41']);

        $this->assertSame('Placeholder Person 41', $guest->fresh()->id_number);
    }

    /** A row written before this cast existed reads the same way. */
    public function test_a_row_that_predates_encryption_still_reads(): void
    {
        $guest = StayGuest::factory()->create(['stay_id' => $this->stay()->id]);

        DB::table('stay_guests')->where('id', $guest->id)->update(['id_number' => 'A123456']);

        $this->assertSame('A123456', $guest->fresh()->id_number);
    }

    /**
     * Ciphertext this key cannot open — a database restored from another
     * environment, or a rotated APP_KEY. Null rather than a 500: a
     * register missing one number is a problem somebody can see and fix,
     * where a page that throws on load tells them nothing.
     */
    public function test_ciphertext_from_another_key_reads_as_nothing_rather_than_throwing(): void
    {
        $guest = StayGuest::factory()->create(['stay_id' => $this->stay()->id]);

        DB::table('stay_guests')->where('id', $guest->id)
            ->update(['id_number' => EncryptedIdentifier::MAGIC.'not-openable-by-this-key']);

        $this->assertNull($guest->fresh()->id_number);
    }

    public function test_an_empty_identifier_is_null_rather_than_ciphertext_of_nothing(): void
    {
        $guest = StayGuest::factory()->create([
            'stay_id' => $this->stay()->id,
            'id_number' => null,
        ]);

        $this->assertNull($guest->fresh()->id_number);
        $this->assertNull(DB::table('stay_guests')->where('id', $guest->id)->value('id_number'));
    }

    // ── The register itself ──────────────────────────────────────────────

    public function test_a_stay_lists_its_guests_with_the_lead_first(): void
    {
        $stay = $this->stay();

        StayGuest::factory()->create(['stay_id' => $stay->id, 'full_name' => 'Second Guest']);
        StayGuest::factory()->lead()->create(['stay_id' => $stay->id, 'full_name' => 'Lead Guest']);

        $this->assertSame(
            ['Lead Guest', 'Second Guest'],
            $stay->fresh()->guests->pluck('full_name')->all(),
        );
    }

    /**
     * The person who paid is often not one of the guests — somebody books
     * a room for a relative — so the lead is recorded, never derived.
     */
    public function test_the_lead_guest_is_recorded_not_derived_from_the_customer(): void
    {
        $stay = $this->stay();

        StayGuest::factory()->lead()->create(['stay_id' => $stay->id, 'full_name' => 'Not The Payer']);

        $this->assertSame('Not The Payer', $stay->fresh()->guests()->lead()->first()?->full_name);
    }

    /** A register entry for a stay that no longer exists is a name belonging to nothing. */
    public function test_deleting_a_stay_takes_its_register_with_it(): void
    {
        $stay = $this->stay();
        $guest = StayGuest::factory()->create(['stay_id' => $stay->id]);

        $stay->delete();

        $this->assertDatabaseMissing('stay_guests', ['id' => $guest->id]);
    }

    public function test_a_maldivian_guest_is_labelled_by_national_id(): void
    {
        $guest = StayGuest::factory()->maldivian()->create(['stay_id' => $this->stay()->id]);

        $this->assertSame(StayGuest::NATIONAL_ID, $guest->id_type);
        $this->assertSame('National ID', $guest->identifierLabel());
    }

    // ── Instant book ─────────────────────────────────────────────────────

    /**
     * §15.6: a Malé room is the owner's own, so there is no partner to
     * ring. The dates are taken at once and the deposit clock starts.
     */
    public function test_an_instant_book_room_is_held_the_moment_it_is_asked_for(): void
    {
        $property = Property::factory()->rental()->create(['min_nights' => 1]);
        $room = RoomType::factory()->create(['property_id' => $property->id, 'quantity' => 1]);

        $stay = app(StayBooking::class)->request(
            Customer::factory()->create(),
            $room,
            CarbonImmutable::parse('2027-03-03'),
            CarbonImmutable::parse('2027-03-05'),
        );

        $this->assertTrue($property->isInstantBookable());
        $this->assertSame(Stay::HELD, $stay->status);
        $this->assertNotNull($stay->expires_at);
        $this->assertNotNull($stay->deposit_due_at);
    }

    /**
     * And a partner's guesthouse still waits, which is §15.2 decision 1 —
     * availability nobody gave Rihla is not Rihla's to promise.
     */
    public function test_a_partner_guesthouse_still_waits_to_be_confirmed(): void
    {
        $property = Property::factory()->create(['min_nights' => 1]);
        $room = RoomType::factory()->create(['property_id' => $property->id, 'quantity' => 1]);

        $stay = app(StayBooking::class)->request(
            Customer::factory()->create(),
            $room,
            CarbonImmutable::parse('2027-03-03'),
            CarbonImmutable::parse('2027-03-05'),
        );

        $this->assertFalse($property->isInstantBookable());
        $this->assertSame(Stay::REQUESTED, $stay->status);
        $this->assertNull($stay->expires_at);
    }

    /** An instant room still cannot be sold twice on the same night. */
    public function test_instant_book_does_not_bypass_the_invariant(): void
    {
        $property = Property::factory()->rental()->create(['min_nights' => 1]);
        $room = RoomType::factory()->create(['property_id' => $property->id, 'quantity' => 1]);
        $booking = app(StayBooking::class);

        $booking->request(
            Customer::factory()->create(), $room,
            CarbonImmutable::parse('2027-03-03'), CarbonImmutable::parse('2027-03-05'),
        );

        $this->expectException(RoomNotAvailable::class);

        $booking->request(
            Customer::factory()->create(), $room->fresh(),
            CarbonImmutable::parse('2027-03-03'), CarbonImmutable::parse('2027-03-05'),
        );
    }

    /** An unpublished instant room is not bookable at all. */
    public function test_an_unpublished_instant_room_is_not_instantly_bookable(): void
    {
        $property = Property::factory()->rental()->unpublished()->create();

        $this->assertTrue($property->instant_book);
        $this->assertFalse($property->isInstantBookable());
    }
}
