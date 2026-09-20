<?php

namespace Tests\Feature;

use App\Exceptions\EditorialStandardNotMet;
use App\Filament\Pages\Today;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\TagsRelationManager;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Tasks\CrmTaskResource;
use App\Filament\Resources\Tasks\Pages\ListCrmTasks;
use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Models\Departure;
use App\Models\Enquiry;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The CRM screens — §8.1.
 *
 * The separation here: **booking staff quote and commit to a price**,
 * because at this size the person on the phone is the person who commits
 * to the number; **reception reads a quotation and cannot write one**,
 * because committing the operator to a price is not a reception job.
 */
class CrmAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    // ── Who may do what ──────────────────────────────────────────────────

    public function test_booking_staff_quote_and_commit_to_the_price(): void
    {
        $staff = $this->staff(Access::BOOKING_STAFF);

        $this->assertTrue($staff->can('quotation.create'));
        $this->assertTrue($staff->can('quotation.send'));
        $this->assertTrue($staff->can('customer.tag'));
    }

    /** Pilgrim Support is the role that answers the phone. */
    public function test_the_people_who_answer_the_phone_read_a_quotation_and_cannot_write_one(): void
    {
        $reception = $this->staff(Access::PILGRIM_SUPPORT);

        $this->assertTrue($reception->can('quotation.view'));
        $this->assertFalse($reception->can('quotation.create'));
        $this->assertFalse($reception->can('quotation.send'));
    }

    /** Work everybody can hand around is work nobody owns. */
    public function test_only_a_supervisor_hands_work_to_somebody_else(): void
    {
        $this->assertFalse($this->staff(Access::BOOKING_STAFF)->can('task.assign'));
        $this->assertTrue($this->staff(Access::OPERATIONS_MANAGER)->can('task.assign'));
    }

    /**
     * A quotation somebody declined is the record of a price this operator
     * could not win on, which is the most useful row in the table.
     */
    public function test_there_is_no_quotation_delete_permission(): void
    {
        $this->assertNotContains('quotation.delete', Access::PERMISSIONS);
    }

    public function test_a_role_without_the_permission_cannot_open_the_screens(): void
    {
        $leader = $this->staff(Access::TOUR_LEADER);

        $this->actingAs($leader)->get(QuotationResource::getUrl('index'))->assertForbidden();
        $this->actingAs($leader)->get(CrmTaskResource::getUrl('index'))->assertForbidden();
        $this->actingAs($leader)->get(Today::getUrl())->assertForbidden();
    }

    // ── The price goes through Money in both directions ──────────────────

    /**
     * Factories run unguarded and the admin form does not, so the only
     * path that proves the conversion is the form itself. The screen takes
     * whole rufiyaa; the column stores laari.
     */
    public function test_a_price_typed_in_whole_rufiyaa_is_stored_in_laari(): void
    {
        $enquiry = Enquiry::factory()->create();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(CreateQuotation::class)
            ->fillForm([
                'enquiry_id' => $enquiry->getKey(),
                'party_size' => 2,
                'currency' => 'MVR',
                'total_minor' => 57_000,
                'valid_until' => now()->addDays(14)->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $quotation = Quotation::sole();

        $this->assertSame(5_700_000, $quotation->total_minor);
        $this->assertSame(28_500, $quotation->perPerson()->major());
    }

    /** And back the other way, or editing a draft would divide the price by 100. */
    public function test_an_existing_price_is_shown_back_in_whole_rufiyaa(): void
    {
        $quotation = Quotation::factory()->create(['total_minor' => 5_700_000]);

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditQuotation::class, ['record' => $quotation->getKey()])
            ->assertFormSet(['total_minor' => 57_000]);
    }

    // ── What the screens show ────────────────────────────────────────────

    /** A date that has passed cannot still read "with the customer". */
    public function test_an_out_of_date_quotation_says_so_on_the_row(): void
    {
        $live = Quotation::factory()->sent()->expiringOn(now()->addDays(5)->toDateString())->create();
        $stale = Quotation::factory()->sent()->expiringOn(now()->subDay()->toDateString())->create();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListQuotations::class)
            ->assertOk()
            ->assertTableColumnStateSet('status', 'With the customer', $live)
            ->assertTableColumnStateSet('status', 'Out of date', $stale);
    }

    /**
     * The list hides editing on a sent quotation, and the model refuses it.
     *
     * Only the second is a guarantee: a console command, a seeder or the
     * next screen somebody writes goes straight past a hidden button.
     */
    public function test_a_sent_quotation_cannot_be_edited(): void
    {
        $draft = Quotation::factory()->create();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListQuotations::class)
            ->assertTableActionVisible('edit', $draft);

        $sent = Quotation::factory()->sent()->create(['total_minor' => 5_700_000]);

        try {
            $sent->update(['total_minor' => 100]);
            $this->fail('A sent quotation was edited.');
        } catch (EditorialStandardNotMet $e) {
            $this->assertStringContainsString('Write a new one', $e->getMessage());
        }

        $this->assertSame(5_700_000, $sent->fresh()->total_minor);
    }

    public function test_declining_from_the_screen_needs_a_reason(): void
    {
        $quotation = Quotation::factory()->sent()->create();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListQuotations::class)
            ->callTableAction('decline', $quotation, ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertTrue($quotation->fresh()->isOpen());
    }

    public function test_the_quotation_badge_counts_offers_nobody_has_answered(): void
    {
        $this->assertNull(QuotationResource::getNavigationBadge());

        Quotation::factory()->create();
        Quotation::factory()->sent()->create();

        // The draft is not an offer anybody is waiting on.
        $this->assertSame('1', QuotationResource::getNavigationBadge());
    }

    /** An unowned task is the cell that has to be noticed. */
    public function test_an_unowned_task_says_nobody_rather_than_showing_a_blank(): void
    {
        $task = CrmTask::factory()->create(['owner_id' => null]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListCrmTasks::class)
            ->assertTableColumnStateSet('owner.name', 'Nobody', $task);
    }

    public function test_marking_a_task_done_from_the_list_records_who(): void
    {
        $user = $this->staff(Access::BOOKING_STAFF);
        $task = CrmTask::factory()->create(['owner_id' => $user->getKey()]);

        Livewire::actingAs($user)
            ->test(ListCrmTasks::class)
            ->callTableAction('complete', $task);

        $this->assertSame($user->getKey(), $task->fresh()->done_by);
    }

    // ── Today ────────────────────────────────────────────────────────────

    /** A shared list where nothing is yours is a list nobody works. */
    public function test_today_separates_your_work_from_everybody_elses(): void
    {
        $me = $this->staff(Access::BOOKING_STAFF);
        $them = $this->staff(Access::BOOKING_STAFF);

        CrmTask::factory()->dueOn(now()->subDay()->toDateString())->create([
            'owner_id' => $me->getKey(),
            'subject' => 'Something of mine',
        ]);
        CrmTask::factory()->dueOn(now()->toDateString())->create([
            'owner_id' => $them->getKey(),
            'subject' => 'Something of theirs',
        ]);

        $page = Livewire::actingAs($me)->test(Today::class)->assertOk();

        $page->assertSee('Something of mine')->assertSee('Something of theirs');

        $this->assertCount(1, $page->instance()->getMine());
        $this->assertCount(1, $page->instance()->getEverybodyElse());
    }

    public function test_today_says_so_when_nothing_is_due(): void
    {
        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(Today::class)
            ->assertOk()
            ->assertSee('Nothing due');
    }

    public function test_the_today_badge_counts_only_your_own_overdue_work(): void
    {
        $me = $this->staff(Access::BOOKING_STAFF);

        CrmTask::factory()->dueOn(now()->subDays(2)->toDateString())->create(['owner_id' => $me->getKey()]);
        CrmTask::factory()->dueOn(now()->subDays(2)->toDateString())->create([
            'owner_id' => $this->staff(Access::BOOKING_STAFF)->getKey(),
        ]);

        $this->actingAs($me);

        $this->assertSame('1', Today::getNavigationBadge());
    }

    // ── The customer 360 ─────────────────────────────────────────────────

    public function test_the_customer_page_gathers_the_whole_history(): void
    {
        $customer = Customer::factory()->create(['name' => 'A Placeholder Customer']);

        $departure = Departure::factory()->withSeats(10)->create([
            'date_start' => now()->subMonths(4)->startOfDay(),
            'date_end' => now()->subMonths(4)->addDays(14)->startOfDay(),
        ]);

        Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'status' => Booking::CONFIRMED,
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => 2_850_000,
        ]);

        CrmTask::factory()->create([
            'about_type' => Customer::class,
            'about_id' => $customer->getKey(),
            'subject' => 'Ring them about next season',
        ]);

        // An enquiry and a quotation, because the first version of this
        // test created neither and the page therefore only ever rendered
        // its empty branches. With one linked it threw a 500 — a call to
        // a method on Enquiry that does not exist — and a browser found
        // it, not this file.
        $enquiry = Enquiry::factory()->create([
            'customer_id' => $customer->getKey(),
            'message' => 'Placeholder enquiry on the dossier.',
        ]);

        Quotation::factory()->sent()->create([
            'enquiry_id' => $enquiry->getKey(),
            'total_minor' => 5_700_000,
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ViewCustomer::class, ['record' => $customer->getKey()])
            ->assertOk()
            ->assertSee('A Placeholder Customer')
            ->assertSee('One journey with us')
            ->assertSee('Ring them about next season')
            ->assertSee('Placeholder enquiry on the dossier.')
            ->assertSee('With the customer')
            // Money formatted once, through Money.
            ->assertSee('MVR 28,500')
            ->assertSee('MVR 57,000');
    }

    /**
     * The two numbers on this page have to agree.
     *
     * It said "Has not travelled with us yet" directly above "Last
     * travelled 14 months ago", because one count took confirmed bookings
     * and the other took every live one.
     */
    public function test_the_customer_page_does_not_contradict_itself_about_travelling(): void
    {
        $customer = Customer::factory()->create();

        Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create([
                'date_start' => now()->subMonths(14)->startOfDay(),
                'date_end' => now()->subMonths(14)->addDays(14)->startOfDay(),
            ])->getKey(),
            'status' => Booking::DRAFT,
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => 1_000_000,
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ViewCustomer::class, ['record' => $customer->getKey()])
            ->assertOk()
            ->assertSee('Has not travelled with us yet')
            ->assertSee('No completed journey on file')
            ->assertDontSee('Last travelled');
    }

    /**
     * The tags table has to be **on the page**, not merely testable.
     *
     * A custom page view is not the one Filament draws relation managers
     * from, so the table did not render at all — while the test below,
     * which mounts the component directly, passed. AGENTS.md warns about
     * this from the other direction; this is the assertion that catches it
     * from this one.
     */
    public function test_the_tags_table_actually_renders_on_the_customer_page(): void
    {
        $customer = Customer::factory()->create();

        CustomerTag::create([
            'customer_id' => $customer->getKey(),
            'tag' => 'prefers ramadan',
        ]);

        $this->actingAs($this->staff(Access::BOOKING_STAFF))
            ->get(CustomerResource::getUrl('view', ['record' => $customer]))
            ->assertOk()
            // The Livewire component's own markup, which is absent when
            // the relation manager is not rendered by the page at all.
            ->assertSee(TagsRelationManager::class, false);
    }

    public function test_a_tag_added_through_the_screen_is_folded(): void
    {
        $customer = Customer::factory()->create();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(TagsRelationManager::class, [
                'ownerRecord' => $customer,
                'pageClass' => ViewCustomer::class,
            ])
            ->callTableAction('create', data: ['tag' => 'Prefers Ramadan'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('prefers ramadan', $customer->fresh()->tags->first()->tag);
    }

    /** A customer row is the thread every booking hangs from. */
    public function test_there_is_no_way_to_delete_a_customer(): void
    {
        $this->assertNotContains('customer.delete', Access::PERMISSIONS);

        $this->assertFalse(
            $this->staff(Access::OPERATIONS_MANAGER)->can('delete', Customer::factory()->create()),
        );
    }

    public function test_a_customer_cannot_be_typed_in_by_hand(): void
    {
        // No create page on the resource at all: one typed here is a
        // duplicate of somebody already in the table, which is the problem
        // the Phase 3 import spent its time on.
        $this->assertArrayNotHasKey('create', CustomerResource::getPages());
    }
}
