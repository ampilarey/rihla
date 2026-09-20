<?php

namespace Tests\Feature;

use App\Exceptions\IllegalPermitTransition;
use App\Exceptions\PrerequisitesNotMet;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Document;
use App\Models\NusukPermit;
use App\Models\Traveller;
use App\Models\User;
use App\Models\VisaApplication;
use App\Services\Nusuk\PermitDesk;
use App\Support\Access;
use App\Support\TravelReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Nusuk permits — §5.4b — and the readiness computed across both workflows.
 *
 * The state this whole design exists to express: a traveller holding a valid
 * visa who is still barred from the Mataf because no permit was issued.
 */
class NusukPermitTest extends TestCase
{
    use RefreshDatabase;

    private function desk(): PermitDesk
    {
        return app(PermitDesk::class);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /** A departure with the Nusuk prerequisites already recorded. */
    private function departure(bool $prerequisitesMet = true): Departure
    {
        return Departure::factory()->withSeats(10)->create([
            'date_start' => now()->addDays(60),
            'date_end' => now()->addDays(74),
            'nusuk_accommodation_recorded_at' => $prerequisitesMet ? now() : null,
            'nusuk_transport_recorded_at' => $prerequisitesMet ? now() : null,
        ]);
    }

    private function booking(?Departure $departure = null, int $travellers = 1): Booking
    {
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => ($departure ?? $this->departure())->getKey(),
            'seats' => $travellers,
        ]);

        for ($i = 0; $i < $travellers; $i++) {
            $booking->travellers()->create([
                'traveller_id' => Traveller::factory()->for($customer)->create([
                    'full_name' => "Traveller {$i}",
                ])->getKey(),
                'occupancy' => 'quad',
                'is_lead' => $i === 0,
            ]);
        }

        return $booking->refresh();
    }

    private function travellerOf(Booking $booking): Traveller
    {
        return $booking->travellers->first()->traveller;
    }

    // ── Two kinds, deliberately separate ──────────────────────────────────

    /**
     * Separate records, not one with two dates. They are granted
     * separately, refused separately, and fail differently: a missing Rawdah
     * slot is a disappointment, a missing Umrah permit is a wasted journey.
     */
    public function test_the_umrah_permit_and_a_rawdah_slot_are_separate_records(): void
    {
        $booking = $this->booking();
        $traveller = $this->travellerOf($booking);

        $umrah = $this->desk()->open($booking, $traveller, NusukPermit::UMRAH);
        $rawdah = $this->desk()->open($booking, $traveller, NusukPermit::RAWDAH);

        $this->assertNotSame($umrah->getKey(), $rawdah->getKey());
        $this->assertSame(2, NusukPermit::count());
    }

    /** Requesting a slot nobody asked for spends one somebody else needed. */
    public function test_opening_for_a_booking_asks_only_for_umrah_permits(): void
    {
        $booking = $this->booking(travellers: 3);

        $this->desk()->openForBooking($booking);

        $this->assertSame(3, NusukPermit::ofKind(NusukPermit::UMRAH)->count());
        $this->assertSame(0, NusukPermit::ofKind(NusukPermit::RAWDAH)->count());
    }

    public function test_opening_twice_does_not_request_twice(): void
    {
        $booking = $this->booking(travellers: 2);

        $this->desk()->openForBooking($booking);
        $this->desk()->openForBooking($booking);

        $this->assertSame(2, NusukPermit::count());
    }

    // ── The prerequisite gate ─────────────────────────────────────────────

    /**
     * §5.4b: accommodation and transport recorded before a permit can be
     * requested. A request that will be refused for a reason we could have
     * seen costs a round trip and leaves a status nobody can explain.
     */
    public function test_a_permit_cannot_be_requested_before_the_prerequisites_are_recorded(): void
    {
        $booking = $this->booking($this->departure(prerequisitesMet: false));
        $permit = $this->desk()->open($booking, $this->travellerOf($booking));

        $this->expectException(PrerequisitesNotMet::class);

        $permit->transitionTo(NusukPermit::REQUESTED);
    }

    public function test_the_message_names_what_is_missing(): void
    {
        $departure = $this->departure(prerequisitesMet: false);
        $departure->forceFill(['nusuk_accommodation_recorded_at' => now()])->save();

        $booking = $this->booking($departure);
        $permit = $this->desk()->open($booking, $this->travellerOf($booking));

        $this->expectExceptionMessage('no transport recorded');

        $permit->transitionTo(NusukPermit::REQUESTED);
    }

    public function test_recording_both_opens_the_gate(): void
    {
        $booking = $this->booking($this->departure(prerequisitesMet: true));
        $permit = $this->desk()->open($booking, $this->travellerOf($booking));

        $permit->transitionTo(NusukPermit::REQUESTED);

        $this->assertSame(NusukPermit::REQUESTED, $permit->fresh()->status);
        $this->assertNotNull($permit->fresh()->requested_at);
    }

    /** A prerequisite nobody requires is not a gate. */
    public function test_a_prerequisite_can_be_switched_off_in_configuration(): void
    {
        $booking = $this->booking($this->departure(prerequisitesMet: false));
        $permit = $this->desk()->open($booking, $this->travellerOf($booking));

        config(['nusuk.prerequisites.accommodation' => false, 'nusuk.prerequisites.transport' => false]);

        $permit->transitionTo(NusukPermit::REQUESTED);

        $this->assertSame(NusukPermit::REQUESTED, $permit->fresh()->status);
    }

    // ── The state machine ─────────────────────────────────────────────────

    public function test_a_refusal_is_final_and_a_retry_is_a_new_attempt(): void
    {
        $booking = $this->booking();
        $permit = $this->desk()->open($booking, $this->travellerOf($booking));
        $permit->transitionTo(NusukPermit::REQUESTED);
        $permit->transitionTo(NusukPermit::REFUSED, 'Passport mismatch');

        $next = $this->desk()->rerequest($permit->fresh());

        $this->assertSame(2, $next->attempt);
        $this->assertSame(NusukPermit::REFUSED, $permit->fresh()->status);
        $this->assertSame('Passport mismatch', $permit->fresh()->refusal_reason);
    }

    /** Saudi systems do withdraw permits, and a record that cannot say so is wrong. */
    public function test_an_issued_permit_can_still_be_cancelled(): void
    {
        $booking = $this->booking();
        $permit = $this->desk()->open($booking, $this->travellerOf($booking));
        $permit->transitionTo(NusukPermit::REQUESTED);
        $permit->transitionTo(NusukPermit::ISSUED);

        $permit->transitionTo(NusukPermit::CANCELLED, 'Withdrawn by Nusuk');

        $this->assertSame(NusukPermit::CANCELLED, $permit->fresh()->status);
    }

    public function test_an_illegal_move_throws(): void
    {
        $booking = $this->booking();
        $permit = $this->desk()->open($booking, $this->travellerOf($booking));

        $this->expectException(IllegalPermitTransition::class);

        $permit->transitionTo(NusukPermit::ISSUED);
    }

    /** A warning, never a refusal: Nusuk decides what it will accept. */
    public function test_a_far_off_rawdah_slot_is_flagged_but_not_refused(): void
    {
        $permit = NusukPermit::factory()->rawdah()->create(['slot_at' => now()->addDays(90)]);

        $this->assertTrue($permit->slotLooksOutOfRange());

        $near = NusukPermit::factory()->rawdah()->create(['slot_at' => now()->addDays(10)]);

        $this->assertFalse($near->slotLooksOutOfRange());
    }

    public function test_an_umrah_permit_has_no_slot_range_to_be_out_of(): void
    {
        $permit = NusukPermit::factory()->create(['slot_at' => now()->addYears(2)]);

        $this->assertFalse($permit->slotLooksOutOfRange());
    }

    // ── Travel readiness, computed ────────────────────────────────────────

    /**
     * The state the whole design exists to express, and the reason §5.4b
     * insists these are two workflows: a valid visa and no permit is not
     * "nearly ready", it is barred at the door.
     */
    public function test_a_valid_visa_without_a_permit_is_not_ready(): void
    {
        $booking = $this->booking();
        $traveller = $this->travellerOf($booking);

        $this->giveVerifiedPassport($traveller);
        $this->issueVisa($booking, $traveller);

        $readiness = TravelReadiness::forTraveller($booking->fresh(), $traveller);

        $this->assertTrue($readiness[TravelReadiness::PASSPORT]);
        $this->assertTrue($readiness[TravelReadiness::VISA]);
        $this->assertFalse($readiness[TravelReadiness::PERMIT]);
        $this->assertFalse(TravelReadiness::isReady($booking->fresh(), $traveller));
    }

    public function test_all_three_together_are_ready(): void
    {
        $booking = $this->booking();
        $traveller = $this->travellerOf($booking);

        $this->giveVerifiedPassport($traveller);
        $this->issueVisa($booking, $traveller);
        $this->issuePermit($booking, $traveller);

        $this->assertTrue(TravelReadiness::isReady($booking->fresh(), $traveller));
    }

    /** Missing a Rawdah slot is a disappointment, not a bar. */
    public function test_a_rawdah_slot_is_not_a_requirement(): void
    {
        $booking = $this->booking();
        $traveller = $this->travellerOf($booking);

        $this->giveVerifiedPassport($traveller);
        $this->issueVisa($booking, $traveller);
        $this->issuePermit($booking, $traveller);

        $this->assertTrue(TravelReadiness::isReady($booking->fresh(), $traveller),
            'No Rawdah slot, and still ready — the two failures are not the same.');
    }

    /** An unread scan is a file, not a document. */
    public function test_an_unverified_passport_does_not_count(): void
    {
        $booking = $this->booking();
        $traveller = $this->travellerOf($booking);

        Document::factory()->create([
            'traveller_id' => $traveller->getKey(),
            'type' => Document::PASSPORT,
            'expires_at' => now()->addYears(5),
        ]);

        $this->issueVisa($booking, $traveller);
        $this->issuePermit($booking, $traveller);

        $this->assertFalse(TravelReadiness::forTraveller($booking->fresh(), $traveller)[TravelReadiness::PASSPORT]);
    }

    /** Measured from the departure date, not from today. */
    public function test_a_passport_expiring_too_close_to_the_departure_does_not_count(): void
    {
        $booking = $this->booking();
        $traveller = $this->travellerOf($booking);

        // Ten days inside the window as measured from the departure. The
        // first draft of this said "eight months from today" and landed
        // about a day the wrong side of the boundary, because the departure
        // is sixty days out and sixty days is not two months. Derived from
        // the departure date instead, so the numbers cannot drift apart
        // again.
        $expires = $booking->departure->date_start->copy()->addMonths(6)->subDays(10);

        // Measured from today this passport is comfortably fine, which is
        // the whole point: a window measured from the wrong date passes it.
        $this->assertTrue(
            $expires->isAfter(now()->addMonths(6)),
            'The fixture has to be acceptable measured from today, or it proves nothing.',
        );

        $this->giveVerifiedPassport($traveller, $expires);
        $this->issueVisa($booking, $traveller);
        $this->issuePermit($booking, $traveller);

        $this->assertFalse(TravelReadiness::forTraveller($booking->fresh(), $traveller)[TravelReadiness::PASSPORT]);
    }

    /** "Not ready" is useless to whoever has to fix it. */
    public function test_the_blockers_name_the_person_and_what_is_missing(): void
    {
        $booking = $this->booking(travellers: 2);
        $first = $booking->travellers->first()->traveller;

        $this->giveVerifiedPassport($first);
        $this->issueVisa($booking, $first);

        $blockers = TravelReadiness::blockers($booking->fresh());

        $this->assertSame([TravelReadiness::PERMIT], $blockers[$first->full_name]);
        $this->assertContains(TravelReadiness::VISA, $blockers['Traveller 1']);
    }

    // ── The departure gate ────────────────────────────────────────────────

    /** §5.4a: a departure cannot be ready while any traveller lacks a permit. */
    public function test_a_departure_is_not_ready_while_anybody_lacks_a_permit(): void
    {
        $departure = $this->departure();
        $booking = $this->booking($departure, travellers: 2);
        $booking->transitionTo(Booking::HELD);
        $booking->transitionTo(Booking::CONFIRMED);

        foreach ($booking->travellers as $line) {
            $this->giveVerifiedPassport($line->traveller);
            $this->issueVisa($booking, $line->traveller);
        }

        $this->issuePermit($booking, $booking->travellers->first()->traveller);

        $this->assertFalse(TravelReadiness::departureIsReady($departure->fresh()));

        $this->issuePermit($booking, $booking->travellers->last()->traveller);

        $this->assertTrue(TravelReadiness::departureIsReady($departure->fresh()));
    }

    /**
     * A draft or a lapsed hold is not a person who is going. Counting them
     * would make every departure permanently un-ready for travellers who
     * were never travelling.
     */
    public function test_an_unconfirmed_booking_does_not_hold_a_departure_back(): void
    {
        $departure = $this->departure();
        $this->booking($departure);

        $this->assertTrue(TravelReadiness::departureIsReady($departure->fresh()));
    }

    /** Readiness is computed, never stored [§5.4a]. */
    public function test_no_readiness_column_exists_to_go_stale(): void
    {
        $columns = array_merge(
            Schema::getColumnListing('bookings'),
            Schema::getColumnListing('departures'),
        );

        foreach ($columns as $column) {
            $this->assertStringNotContainsString('ready', $column,
                'Travel readiness must be computed from the records, not stored where it can go stale.');
        }
    }

    // ── Access ────────────────────────────────────────────────────────────

    /** Recording a dealing with a Saudi system is not editing the website. */
    public function test_the_content_manager_cannot_record_the_nusuk_prerequisites(): void
    {
        $this->assertFalse($this->staff(Access::CONTENT_MANAGER)->can('departure.nusuk'));
        $this->assertTrue($this->staff(Access::VISA_STAFF)->can('departure.nusuk'));
    }

    public function test_nobody_may_delete_a_permit(): void
    {
        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $this->assertFalse($this->staff($role)->can('permit.delete'), "[{$role}] may delete permits.");
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function giveVerifiedPassport(Traveller $traveller, ?\DateTimeInterface $expires = null): Document
    {
        return Document::factory()->create([
            'traveller_id' => $traveller->getKey(),
            'type' => Document::PASSPORT,
            'status' => Document::VERIFIED,
            'expires_at' => $expires ?? now()->addYears(5),
        ]);
    }

    private function issueVisa(Booking $booking, Traveller $traveller): void
    {
        $application = VisaApplication::factory()->create([
            'booking_id' => $booking->getKey(),
            'traveller_id' => $traveller->getKey(),
        ]);

        $application->transitionTo(VisaApplication::PREPARING);
        $application->transitionTo(VisaApplication::SUBMITTED);
        $application->transitionTo(VisaApplication::ISSUED);
    }

    private function issuePermit(Booking $booking, Traveller $traveller): void
    {
        $permit = $this->desk()->open($booking, $traveller);
        $permit->transitionTo(NusukPermit::REQUESTED);
        $permit->transitionTo(NusukPermit::ISSUED);
    }
}
