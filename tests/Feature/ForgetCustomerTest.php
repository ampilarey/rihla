<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DocumentVersion;
use App\Models\Enquiry;
use App\Models\Notice;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayGuest;
use App\Models\Traveller;
use App\Services\Documents\DocumentWallet;
use App\Services\Payments\SlipVault;
use App\Support\Anonymisation;
use App\Support\Forgetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Honouring a deletion request for one person — §10.4.
 *
 * "Delete my data" and "keep your books" are both obligations pointing in
 * opposite directions, so the whole design is the line between them:
 *
 * 1. **The person goes, including the files.** Name, contacts, national
 *    ID, passport number and the scan of it, medical notes, emergency
 *    contact, the free text staff typed, and every portal link that would
 *    open their booking.
 * 2. **The financial record stays and still adds up.** Bookings and
 *    payments keep their references, amounts, dates and statuses.
 * 3. **Both refusals fail safe.** A table nobody classified stops the run,
 *    and so does a booking that is not finished with.
 */
class ForgetCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    /**
     * Somebody who travelled, paid, and left a trail across the schema.
     *
     * @return array{Customer, Traveller, Booking, Payment, DocumentVersion}
     */
    private function somebodyWhoTravelled(string $status = Booking::COMPLETED): array
    {
        $customer = Customer::factory()->create([
            'name' => 'Aishath Real Person',
            'email' => 'aishath@realdomain.example',
            'phone' => '7712345',
            'national_id' => 'A123456',
            'address' => 'A real address',
            'notes' => 'A real note a member of staff typed.',
        ]);

        $traveller = Traveller::factory()->create([
            'customer_id' => $customer->getKey(),
            'full_name' => 'Aishath Real Person',
            'passport_number' => 'MV1234567',
            'medical_notes' => 'A real medical note',
            'emergency_contact_name' => 'Ibrahim Real Person',
            'emergency_contact_phone' => '9998888',
        ]);

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(20)->create([
                'package_id' => Package::factory()->create()->getKey(),
            ])->getKey(),
            'status' => $status,
            'seats' => 1,
            'total_minor' => 4500000,
            'paid_minor' => 4500000,
            'notes' => 'She asked for a window seat.',
        ]);

        $payment = Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'status' => Payment::SUCCEEDED,
            'amount_minor' => 4500000,
            'payer_name' => 'Aishath Real Person',
            'payer_bank' => 'BML 7701234567',
        ]);

        $version = app(DocumentWallet::class)->store(
            $traveller,
            UploadedFile::fake()->createWithContent('passport.pdf', 'A real passport scan.'),
            'travel',
            'passport',
        );

        Enquiry::factory()->create([
            'customer_id' => $customer->getKey(),
            'name' => 'Aishath Real Person',
            'email' => 'aishath@realdomain.example',
            'phone' => '7712345',
            'message' => 'Is there room in Ramadan?',
        ]);

        return [$customer, $traveller, $booking, $payment, $version];
    }

    // ── The map covers the schema ────────────────────────────────────────

    /**
     * The assertion that makes this trustworthy a year from now.
     *
     * A new table holding personal data that nobody said how to reach is a
     * table whose rows survive a deletion request that was reported as
     * honoured. That is the worst outcome available here, so it fails in a
     * test rather than in front of somebody who asked to be forgotten.
     */
    public function test_every_table_holding_personal_data_says_how_to_reach_one_person(): void
    {
        $unreached = Forgetting::unreached();

        $this->assertSame(
            [],
            $unreached,
            'Unreached: '.implode(', ', $unreached)
            .'. Add each to REACHED or NOT_ONE_PERSONS in App\Support\Forgetting.',
        );
    }

    /** And nothing names a table that no longer exists. */
    public function test_no_route_is_kept_for_a_table_that_was_dropped(): void
    {
        $missing = Forgetting::missingFromSchema();

        $this->assertSame([], $missing, 'Named but absent: '.implode(', ', $missing));
    }

    public function test_every_reached_table_is_one_the_scrubber_knows(): void
    {
        foreach (array_keys(Forgetting::REACHED) as $table) {
            $this->assertArrayHasKey(
                $table,
                Anonymisation::SCRUB,
                $table.' is reachable but has no columns to scrub.',
            );
        }
    }

    // ── The person goes ──────────────────────────────────────────────────

    public function test_the_person_is_erased(): void
    {
        [$customer, $traveller] = $this->somebodyWhoTravelled();

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $customer->refresh();
        $traveller->refresh();

        foreach ([$customer->name, $customer->email, $customer->phone, $customer->national_id,
            $customer->address, $customer->notes, $traveller->full_name,
            $traveller->passport_number, $traveller->emergency_contact_name] as $value) {
            $this->assertStringNotContainsString('Real Person', (string) $value);
            $this->assertStringNotContainsString('A123456', (string) $value);
            $this->assertStringNotContainsString('MV1234567', (string) $value);
        }

        $this->assertNull($traveller->medical_notes);
    }

    /** Including the free text staff typed into the booking. */
    public function test_the_notes_staff_typed_go_too(): void
    {
        [$customer, , $booking] = $this->somebodyWhoTravelled();

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $this->assertStringNotContainsString('window seat', (string) $booking->fresh()->notes);
    }

    public function test_the_enquiry_they_sent_is_erased(): void
    {
        [$customer] = $this->somebodyWhoTravelled();

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $enquiry = DB::table('enquiries')->where('customer_id', $customer->getKey())->first();

        $this->assertNotNull($enquiry);
        $this->assertStringNotContainsString('Real Person', (string) $enquiry->name);
        $this->assertStringNotContainsString('Ramadan', (string) $enquiry->message);
    }

    /**
     * The passport scan is deleted from disk, not merely dereferenced.
     *
     * Nulling the path and leaving the file is the failure that looks
     * exactly like success: the application cannot reach it, the next
     * backup still carries it.
     */
    public function test_the_passport_scan_is_deleted_from_disk(): void
    {
        [$customer, , , , $version] = $this->somebodyWhoTravelled();

        $this->assertTrue(Storage::disk($version->disk)->exists($version->path));

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $this->assertFalse(Storage::disk($version->disk)->exists($version->path));
    }

    public function test_the_transfer_slip_is_deleted_from_disk_too(): void
    {
        [$customer, , $booking] = $this->somebodyWhoTravelled();

        $payment = Payment::factory()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->getKey(),
            'status' => Payment::SUCCEEDED,
        ]);

        app(SlipVault::class)->attach(
            $payment,
            UploadedFile::fake()->createWithContent('slip.pdf', 'An account number and a name.'),
        );

        $payment->refresh();
        $disk = (string) $payment->slip_disk;
        $path = (string) $payment->slip_path;

        $this->assertTrue(Storage::disk($disk)->exists($path));

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $this->assertFalse(Storage::disk($disk)->exists($path));
    }

    // ── The books stay ───────────────────────────────────────────────────

    /**
     * The line this whole command is drawn along.
     *
     * A travel agency has to be able to show what it was paid, by whom
     * and for which journey, years after the journey. It does not have to
     * keep the passport scan. So the money survives and the person does
     * not — and if this ever stops being true, the accounts stop adding
     * up and nobody notices until an audit.
     */
    public function test_the_money_survives_exactly(): void
    {
        [$customer, , $booking, $payment] = $this->somebodyWhoTravelled();

        $reference = $booking->reference;
        $total = $booking->total_minor;

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $booking->refresh();
        $payment->refresh();

        $this->assertSame($reference, $booking->reference);
        $this->assertSame($total, $booking->total_minor);
        $this->assertSame(Booking::COMPLETED, $booking->status);
        $this->assertSame(4500000, $payment->amount_minor);
        $this->assertSame(Payment::SUCCEEDED, $payment->status);
    }

    /** But the payer's name on it is not the financial record. */
    public function test_the_payers_name_is_not_part_of_the_money(): void
    {
        [$customer, , , $payment] = $this->somebodyWhoTravelled();

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $this->assertStringNotContainsString('Real Person', (string) $payment->fresh()->payer_name);
    }

    // ── Nobody else is touched ───────────────────────────────────────────

    public function test_another_customer_is_left_exactly_as_they_were(): void
    {
        [$customer] = $this->somebodyWhoTravelled();

        $other = Customer::factory()->create(['name' => 'Hawwa Somebody Else', 'email' => 'hawwa@example.test']);
        $otherTraveller = Traveller::factory()->create([
            'customer_id' => $other->getKey(),
            'full_name' => 'Hawwa Somebody Else',
            'passport_number' => 'MV7654321',
        ]);

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $this->assertSame('Hawwa Somebody Else', $other->fresh()->name);
        $this->assertSame('MV7654321', $otherTraveller->fresh()->passport_number);
    }

    // ── The refusals ─────────────────────────────────────────────────────

    /**
     * You cannot forget somebody you are about to fly.
     *
     * A held or confirmed seat is money and a place on an aircraft, and a
     * traveller with no name cannot be checked in or issued a permit.
     */
    public function test_it_refuses_while_a_booking_is_not_finished_with(): void
    {
        [$customer, $traveller] = $this->somebodyWhoTravelled(Booking::CONFIRMED);

        $this->artisan('data:forget', ['customer' => $customer->getKey()])
            ->expectsOutputToContain('not finished with')
            ->assertFailed();

        $this->assertSame('Aishath Real Person', $traveller->fresh()->full_name);
    }

    public function test_a_cancelled_booking_does_not_block_it(): void
    {
        [$customer] = $this->somebodyWhoTravelled(Booking::CANCELLED);

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $this->assertStringNotContainsString('Real Person', (string) $customer->fresh()->name);
    }

    public function test_an_unknown_customer_is_an_error_not_a_silent_success(): void
    {
        $this->artisan('data:forget', ['customer' => 'nobody@example.invalid'])
            ->expectsOutputToContain('No customer matches')
            ->assertFailed();
    }

    public function test_it_finds_somebody_by_email_as_well_as_by_id(): void
    {
        [$customer] = $this->somebodyWhoTravelled();

        $this->artisan('data:forget', ['customer' => 'aishath@realdomain.example'])->assertSuccessful();

        $this->assertStringNotContainsString('Real Person', (string) $customer->fresh()->name);
    }

    // ── The dry run ──────────────────────────────────────────────────────

    public function test_a_dry_run_changes_nothing_at_all(): void
    {
        [$customer, $traveller, , , $version] = $this->somebodyWhoTravelled();

        $this->artisan('data:forget', ['customer' => $customer->getKey(), '--dry-run' => true])
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        $this->assertSame('Aishath Real Person', $customer->fresh()->name);
        $this->assertSame('MV1234567', $traveller->fresh()->passport_number);
        $this->assertTrue(Storage::disk($version->disk)->exists($version->path));
    }

    // ── The record of the request ────────────────────────────────────────

    /**
     * An auditor needs to know a request was honoured and when. Recording
     * *whose* would undo the forgetting, so the row carries the id and
     * nothing else.
     */
    public function test_it_records_that_a_request_was_honoured_without_naming_anybody(): void
    {
        [$customer] = $this->somebodyWhoTravelled();

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $log = DB::table('audit_logs')->where('event', 'forgotten')->first();

        $this->assertNotNull($log);
        $this->assertSame($customer->getKey(), (int) $log->auditable_id);
        $this->assertStringNotContainsString('Real Person', (string) $log->new_values);
        $this->assertStringNotContainsString('Real Person', (string) $log->user_name);
    }

    /**
     * **A guesthouse deposit must not survive a deletion request.**
     *
     * Payments have been polymorphic since §15.3, and this command matched
     * `payable_type = Booking` alone — with a note saying a stay's money
     * would be reached "when Phase 9 gives it a customer of its own".
     * Phase 9 did, in §15.4, and nothing came back to that line. Between
     * then and Phase 11 a stay's payment would have been reported erased
     * and left sitting there with the payer's name on it.
     *
     * Found by reading that comment rather than by anything failing, which
     * is exactly why it gets a test.
     */
    public function test_a_stays_payment_and_register_are_erased_too(): void
    {
        [$customer] = $this->somebodyWhoTravelled();

        $property = Property::factory()->create();
        $room = RoomType::factory()->create(['property_id' => $property->getKey()]);

        $stay = Stay::factory()->create([
            'customer_id' => $customer->getKey(),
            'property_id' => $property->getKey(),
            'room_type_id' => $room->getKey(),
            'special_requests' => 'We are on honeymoon.',
        ]);

        $payment = Payment::factory()->create([
            'payable_type' => Stay::class,
            'payable_id' => $stay->getKey(),
            'payer_name' => 'Aishath Real Person',
        ]);

        $guest = StayGuest::factory()->create([
            'stay_id' => $stay->getKey(),
            'full_name' => 'Aishath Real Person',
            'id_number' => 'P7654321',
        ]);

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $this->assertNotSame('Aishath Real Person', $payment->fresh()->payer_name);
        $this->assertStringNotContainsString('honeymoon', (string) $stay->fresh()->special_requests);
        $this->assertNotSame('Aishath Real Person', $guest->fresh()->full_name);
        $this->assertNotSame('P7654321', $guest->fresh()->id_number);
    }

    /**
     * A notice's body is scrubbed, whichever kind of thing it is about —
     * §15.7.
     *
     * `notices` became polymorphic when a stay needed to be told things,
     * and `Forgetting::REACHED` still said `booking`. That is the two-list
     * trap `AGENTS.md` records, in both its halves. The **loud** half is a
     * query against a `booking_id` column that no longer exists, which
     * stops the command. The **quiet** half is worse and is what this
     * test is for: a guesthouse notice — *"USD 60 holds Sea-facing double
     * from 3 March, the hold runs out on…"* — sitting unscrubbed on a
     * public test server after a request this command reported as
     * honoured.
     *
     * Both owners asserted, because getting one right and leaving the
     * other is precisely how the payments version of this shipped.
     *
     * Note what is *not* claimed: the row survives, and so does the
     * headline. `Anonymisation::SCRUB` classifies `notices` as
     * `['body' => 'text']` on the reasoning that a headline comes from a
     * closed vocabulary the sweep writes — "You travel tomorrow", "We
     * still need a passport" — and names nobody. The body is the half
     * that carries dates, amounts and a property, and it is the half that
     * goes.
     */
    public function test_a_notice_about_either_a_booking_or_a_stay_is_scrubbed(): void
    {
        [$customer, , $booking] = $this->somebodyWhoTravelled();

        $property = Property::factory()->create();
        $room = RoomType::factory()->create(['property_id' => $property->getKey()]);

        $stay = Stay::factory()->create([
            'customer_id' => $customer->getKey(),
            'property_id' => $property->getKey(),
            'room_type_id' => $room->getKey(),
        ]);

        $onBooking = Notice::raise(
            $booking,
            Notice::BOOKING_CONFIRMED,
            'Your booking is confirmed',
            'Departure is 3 March 2027.',
        );

        $onStay = Notice::raise(
            $stay,
            Notice::DEPOSIT_DUE,
            'Maafushi View has your rooms',
            'USD 60 holds Sea-facing double from 3 March 2027.',
        );

        $this->artisan('data:forget', ['customer' => $customer->getKey()])->assertSuccessful();

        $this->assertStringNotContainsString('3 March', (string) $onBooking->fresh()->body);
        $this->assertStringNotContainsString(
            'Sea-facing double',
            (string) $onStay->fresh()->body,
            'A guesthouse notice outlived the erasure unscrubbed.',
        );
    }
}
