<?php

namespace Tests\Feature;

use App\Exceptions\IllegalVisaTransition;
use App\Filament\Resources\Visas\Pages\ListVisaApplications;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Document;
use App\Models\Traveller;
use App\Models\User;
use App\Models\VisaApplication;
use App\Services\Visa\VisaDesk;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Visa applications — §5.4a, and deliberately not Nusuk permits [R-4].
 *
 * A traveller can hold a valid visa and still be barred from the Mataf and
 * the Rawdah without a permit. Keeping the two workflows apart is what lets
 * the system say so.
 */
class VisaApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function desk(): VisaDesk
    {
        return app(VisaDesk::class);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function booking(int $travellers = 1): Booking
    {
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create()->getKey(),
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

    // ── Opening ───────────────────────────────────────────────────────────

    /** A visa is granted to a person, not to a party. */
    public function test_one_application_is_opened_for_each_traveller(): void
    {
        $booking = $this->booking(3);

        $this->desk()->openForBooking($booking, 'umrah');

        $this->assertSame(3, VisaApplication::count());
        $this->assertSame([1, 1, 1], VisaApplication::pluck('attempt')->all());
    }

    /** Two submissions to a government for one traveller is the failure to avoid. */
    public function test_opening_twice_does_not_queue_two_applications(): void
    {
        $booking = $this->booking(2);

        $this->desk()->openForBooking($booking);
        $this->desk()->openForBooking($booking);

        $this->assertSame(2, VisaApplication::count());
    }

    // ── The state machine ─────────────────────────────────────────────────

    public function test_a_stage_records_who_why_and_what_they_saw(): void
    {
        $application = VisaApplication::factory()->create();
        $officer = $this->staff(Access::VISA_STAFF);
        $evidence = Document::factory()->create(['traveller_id' => $application->traveller_id]);

        $application->transitionTo(VisaApplication::PREPARING, 'Collecting the photo page', $officer, $evidence);

        $event = $application->events->last();

        $this->assertSame(VisaApplication::NOT_STARTED, $event->from_status);
        $this->assertSame(VisaApplication::PREPARING, $event->to_status);
        $this->assertSame($officer->getKey(), $event->user_id);
        $this->assertSame($evidence->getKey(), $event->document_id);
        $this->assertSame('Collecting the photo page', $event->reason);
    }

    public function test_an_illegal_move_throws(): void
    {
        $application = VisaApplication::factory()->create();

        $this->expectException(IllegalVisaTransition::class);

        $application->transitionTo(VisaApplication::ISSUED);
    }

    /**
     * An application returned for more information has not been refused.
     * Treating that as a rejection would burn an attempt nobody refused.
     */
    public function test_a_submitted_application_can_go_back_for_more_information(): void
    {
        $application = VisaApplication::factory()->create();
        $application->transitionTo(VisaApplication::PREPARING);
        $application->transitionTo(VisaApplication::SUBMITTED);

        $application->transitionTo(VisaApplication::PREPARING, 'They want a clearer photo');

        $this->assertSame(VisaApplication::PREPARING, $application->fresh()->status);
        $this->assertSame(1, $application->fresh()->attempt, 'No attempt is burned.');
    }

    public function test_submitting_and_issuing_stamp_their_times(): void
    {
        $application = VisaApplication::factory()->create();
        $application->transitionTo(VisaApplication::PREPARING);
        $application->transitionTo(VisaApplication::SUBMITTED);
        $this->assertNotNull($application->fresh()->submitted_at);

        $application->transitionTo(VisaApplication::ISSUED);
        $this->assertNotNull($application->fresh()->issued_at);
    }

    // ── Refusal and re-application ────────────────────────────────────────

    /**
     * §5.4a: re-application is a first-class path. A refusal is a fact about
     * a particular submission, and reopening the row to try again destroys
     * the only evidence of what was sent.
     */
    public function test_a_refusal_is_final_and_a_retry_is_a_new_attempt(): void
    {
        $application = VisaApplication::factory()->create(['visa_type' => 'umrah']);
        $application->transitionTo(VisaApplication::PREPARING);
        $application->transitionTo(VisaApplication::SUBMITTED);
        $application->transitionTo(VisaApplication::REJECTED, 'Photo page unreadable');

        $next = $this->desk()->reapply($application->fresh());

        $this->assertSame(2, $next->attempt);
        $this->assertSame('umrah', $next->visa_type, 'The second attempt is usually the same application, corrected.');
        $this->assertSame(VisaApplication::NOT_STARTED, $next->status);

        $refused = $application->fresh();

        $this->assertSame(VisaApplication::REJECTED, $refused->status, 'The refusal is untouched…');
        $this->assertSame('Photo page unreadable', $refused->rejection_reason, '…and so is its stated reason.');
        $this->assertNotNull($refused->rejected_at);
    }

    public function test_a_refused_application_cannot_be_reopened(): void
    {
        $application = VisaApplication::factory()->create();
        $application->transitionTo(VisaApplication::PREPARING);
        $application->transitionTo(VisaApplication::SUBMITTED);
        $application->transitionTo(VisaApplication::REJECTED, 'Refused');

        $this->expectExceptionMessage('a retry is a new attempt');

        $application->transitionTo(VisaApplication::PREPARING);
    }

    public function test_opening_after_a_refusal_continues_the_numbering(): void
    {
        $booking = $this->booking(1);
        $traveller = $booking->travellers->first()->traveller;

        $first = $this->desk()->open($booking, $traveller);
        $first->transitionTo(VisaApplication::PREPARING);
        $first->transitionTo(VisaApplication::SUBMITTED);
        $first->transitionTo(VisaApplication::REJECTED, 'No');

        $second = $this->desk()->open($booking, $traveller);

        $this->assertSame(2, $second->attempt);
        $this->assertSame(2, VisaApplication::count());
    }

    // ── Service levels ────────────────────────────────────────────────────

    /**
     * Computed from config, never stored: a stored "overdue" flag is wrong
     * the moment the clock passes it, and right again only if something
     * remembers to clear it.
     */
    public function test_an_application_past_its_service_level_is_stalled(): void
    {
        $application = VisaApplication::factory()->create();
        $application->transitionTo(VisaApplication::PREPARING);
        $application->transitionTo(VisaApplication::SUBMITTED);

        $this->assertFalse($application->fresh()->isStalled());

        $this->travel(8)->days();

        $this->assertTrue($application->fresh()->isStalled());
    }

    public function test_the_service_level_is_configuration_not_a_constant(): void
    {
        $application = VisaApplication::factory()->create();
        $application->transitionTo(VisaApplication::PREPARING);
        $application->transitionTo(VisaApplication::SUBMITTED);

        $this->travel(8)->days();
        $this->assertTrue($application->fresh()->isStalled());

        config(['visa.sla_days.submitted' => 30]);

        $this->assertFalse($application->fresh()->isStalled(),
            'A mid-season change must be a config edit, not a deployment.');
    }

    public function test_a_closed_application_is_never_stalled(): void
    {
        $application = VisaApplication::factory()->create();
        $application->transitionTo(VisaApplication::PREPARING);
        $application->transitionTo(VisaApplication::SUBMITTED);
        $application->transitionTo(VisaApplication::ISSUED);

        $this->travel(60)->days();

        $this->assertFalse($application->fresh()->isStalled());
        $this->assertEmpty($this->desk()->stalled());
    }

    // ── [R-4]: two workflows, not one ─────────────────────────────────────

    /**
     * The one thing this slice must not do. A traveller holding an issued
     * visa is not thereby cleared to travel — the Nusuk permit is a separate
     * authorisation, and nothing here may imply otherwise.
     */
    public function test_an_issued_visa_says_nothing_about_a_permit(): void
    {
        $application = VisaApplication::factory()->create();
        $application->transitionTo(VisaApplication::PREPARING);
        $application->transitionTo(VisaApplication::SUBMITTED);
        $application->transitionTo(VisaApplication::ISSUED);

        $columns = array_keys($application->fresh()->getAttributes());

        foreach ($columns as $column) {
            $this->assertStringNotContainsString('permit', $column,
                'A visa application must carry no permit state: one combined field cannot express "visa issued, still barred".');
            $this->assertStringNotContainsString('ready', $column,
                'Travel readiness is computed from both workflows, never stored here.');
        }
    }

    // ── Access ────────────────────────────────────────────────────────────

    public function test_visa_staff_can_work_the_queue(): void
    {
        VisaApplication::factory()->create([
            'traveller_id' => Traveller::factory()->create(['full_name' => 'Aminath Ibrahim'])->getKey(),
        ]);

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(ListVisaApplications::class)
            ->assertOk()
            ->assertSee('Aminath Ibrahim');
    }

    public function test_the_content_manager_cannot(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get('/staff/visas/visa-applications')
            ->assertForbidden();
    }

    public function test_pilgrim_support_may_read_but_not_move_an_application(): void
    {
        $support = $this->staff(Access::PILGRIM_SUPPORT);

        $this->assertTrue($support->can('visa.view'));
        $this->assertFalse($support->can('visa.update'));
    }

    /** A refusal that can be removed is evidence that can be removed. */
    public function test_nobody_may_delete_an_application(): void
    {
        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $this->assertFalse($this->staff($role)->can('visa.delete'), "[{$role}] may delete visa applications.");
        }
    }
}
