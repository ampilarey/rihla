<?php

namespace Tests\Feature;

use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Enquiries\Pages\ListEnquiries;
use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The enquiry inbox — §8.1.
 *
 * Rendered rather than status-checked, for the reason AGENTS.md gives: a
 * Filament page answers 200 while a column closure throws in its own
 * Livewire request.
 */
class EnquiryAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    // ── Who may look ──────────────────────────────────────────────────────

    public function test_booking_staff_can_work_the_inbox(): void
    {
        Enquiry::factory()->create(['name' => 'Ibrahim Waheed']);

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListEnquiries::class)
            ->assertOk()
            ->assertSee('Ibrahim Waheed');
    }

    public function test_booking_staff_can_reach_the_screen_over_http(): void
    {
        $this->actingAs($this->staff(Access::BOOKING_STAFF))
            ->get(EnquiryResource::getUrl('index'))
            ->assertOk();
    }

    public function test_the_content_manager_cannot(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get(EnquiryResource::getUrl('index'))
            ->assertForbidden();
    }

    /** Handing work to somebody else is a supervisor's call at this size. */
    public function test_booking_staff_cannot_reassign(): void
    {
        $enquiry = Enquiry::factory()->create();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListEnquiries::class)
            ->assertTableActionHidden('assign', $enquiry);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListEnquiries::class)
            ->assertTableActionVisible('assign', $enquiry);
    }

    // ── The queue ────────────────────────────────────────────────────────

    /**
     * The default tab is the problem list, not the whole list.
     *
     * A screen that opens on "all enquiries" is a shared inbox with extra
     * steps, which is the thing §8.1 says the minimal version beats.
     */
    public function test_the_screen_opens_on_the_enquiries_nobody_has_promised_anything_about(): void
    {
        $adrift = Enquiry::factory()->create(['name' => 'Nobody Owns Me']);
        $handled = Enquiry::factory()->working()->create([
            'name' => 'Being Dealt With',
            'assigned_to' => $this->staff(Access::BOOKING_STAFF)->getKey(),
            'next_action' => 'Call back',
            'next_action_at' => now()->addDay(),
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListEnquiries::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$adrift])
            ->assertCanNotSeeTableRecords([$handled]);
    }

    /** Worked from the front: newest-first is how the old one never gets answered. */
    public function test_the_oldest_is_first(): void
    {
        $old = Enquiry::factory()->create(['name' => 'Asked on Monday', 'created_at' => now()->subDays(4)]);
        $new = Enquiry::factory()->create(['name' => 'Asked just now']);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListEnquiries::class)
            ->assertCanSeeTableRecords([$old, $new], inOrder: true);
    }

    // ── The two columns that matter ──────────────────────────────────────

    public function test_planning_the_next_action_takes_it_out_of_the_queue(): void
    {
        $enquiry = Enquiry::factory()->create();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListEnquiries::class)
            ->callTableAction('plan', $enquiry, [
                'next_action' => 'Call back with the Shawwal price',
                'next_action_at' => now()->addDay()->toDateString(),
            ]);

        $enquiry->refresh();

        $this->assertSame('Call back with the Shawwal price', $enquiry->next_action);
        // Planning something is working on it.
        $this->assertSame(Enquiry::WORKING, $enquiry->status);
        // Still adrift, because nobody owns it — an owner and a next action,
        // not one of the two.
        $this->assertTrue($enquiry->isAdrift());
    }

    public function test_both_the_action_and_the_date_are_required(): void
    {
        $enquiry = Enquiry::factory()->create();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListEnquiries::class)
            ->callTableAction('plan', $enquiry, ['next_action' => 'Call back', 'next_action_at' => null])
            ->assertHasTableActionErrors(['next_action_at']);
    }

    public function test_assigning_and_planning_together_clear_it(): void
    {
        $enquiry = Enquiry::factory()->create();
        $owner = $this->staff(Access::BOOKING_STAFF);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListEnquiries::class)
            ->callTableAction('assign', $enquiry, ['assigned_to' => $owner->getKey()])
            ->callTableAction('plan', $enquiry, [
                'next_action' => 'Call back',
                'next_action_at' => now()->addDay()->toDateString(),
            ]);

        $this->assertFalse($enquiry->fresh()->isAdrift());
        $this->assertSame(0, Enquiry::adrift()->count());
    }

    // ── Closing ──────────────────────────────────────────────────────────

    public function test_booking_creates_a_customer(): void
    {
        $enquiry = Enquiry::factory()->create(['name' => 'Ibrahim Waheed', 'phone' => '7712345']);

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListEnquiries::class)
            ->callTableAction('won', $enquiry);

        $enquiry->refresh();

        $this->assertSame(Enquiry::WON, $enquiry->status);
        $this->assertNotNull($enquiry->customer_id);
        $this->assertSame('Ibrahim Waheed', Customer::sole()->name);
    }

    /** The import's lesson, reused: do not create a second copy of somebody. */
    public function test_booking_reuses_a_customer_with_the_same_number(): void
    {
        $existing = Customer::factory()->create(['name' => 'Ibrahim Waheed', 'phone' => '+960 771 2345']);
        $enquiry = Enquiry::factory()->create(['name' => 'Ibrahim W', 'phone' => '7712345']);

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListEnquiries::class)
            ->callTableAction('won', $enquiry);

        $this->assertSame(1, Customer::count());
        $this->assertSame($existing->getKey(), $enquiry->fresh()->customer_id);
    }

    /**
     * "Too expensive" and "went with a competitor" are different problems,
     * and reading them back is the point of keeping lost enquiries.
     */
    public function test_losing_one_needs_a_reason(): void
    {
        $enquiry = Enquiry::factory()->create();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListEnquiries::class)
            ->callTableAction('lost', $enquiry, ['lost_reason' => ''])
            ->assertHasTableActionErrors(['lost_reason']);

        $this->assertSame(Enquiry::NEW, $enquiry->fresh()->status);

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListEnquiries::class)
            ->callTableAction('lost', $enquiry, ['lost_reason' => 'Went with a competitor on price']);

        $this->assertSame(Enquiry::LOST, $enquiry->fresh()->status);
        $this->assertSame('Went with a competitor on price', $enquiry->fresh()->lost_reason);
    }

    /** A lost enquiry is the record of a customer this operator did not win. */
    public function test_nobody_may_delete_an_enquiry(): void
    {
        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $this->assertFalse(
                User::factory()->create()->assignRole($role)->can('enquiry.delete'),
                "[{$role}] may delete enquiries.",
            );
        }
    }

    // ── History ──────────────────────────────────────────────────────────

    public function test_every_move_is_written_into_the_history(): void
    {
        $enquiry = Enquiry::factory()->create();
        $staff = $this->staff(Access::BOOKING_STAFF);

        Livewire::actingAs($staff)
            ->test(ListEnquiries::class)
            ->callTableAction('note', $enquiry, ['body' => 'Rang, no answer.']);

        $note = $enquiry->fresh()->notes()->sole();

        $this->assertSame('Rang, no answer.', $note->body);
        $this->assertSame($staff->getKey(), $note->user_id);
    }
}
