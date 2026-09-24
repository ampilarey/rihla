<?php

namespace Tests\Feature;

use App\Filament\Resources\Stays\Pages\ListStays;
use App\Filament\Resources\Stays\StayResource;
use App\Models\Customer;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayGuest;
use App\Models\User;
use App\Services\Stays\StayBooking;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Stays board — §15.4 (Phase 9.3).
 *
 * The screen exists for one decision, made once per request: the partner
 * said yes, or the partner said no. These tests are about that decision
 * being real — that pressing Confirm genuinely takes the dates, and that
 * pressing it when the dates have gone says so rather than pretending.
 */
class StaysBoardTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $room;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();

        $property = Property::factory()->create(['currency' => 'USD', 'min_nights' => 1]);

        $this->room = RoomType::factory()->create([
            'property_id' => $property->id,
            'quantity' => 1,
            'base_rate_minor' => 10000,
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    private function request(): Stay
    {
        return app(StayBooking::class)->request(
            $this->customer,
            $this->room->fresh(),
            CarbonImmutable::parse('2027-03-03'),
            CarbonImmutable::parse('2027-03-05'),
        );
    }

    // ── Getting in ───────────────────────────────────────────────────────

    public function test_a_super_admin_can_reach_the_board(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(StayResource::getUrl('index'))
            ->assertOk();
    }

    /**
     * A stay carries somebody's holiday and somebody's money. Editing the
     * website is not a reason to see either — the same line the property
     * permissions draw.
     */
    public function test_the_content_manager_cannot_reach_the_board(): void
    {
        $editor = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        $this->actingAs($editor)
            ->get(StayResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_the_board_lists_stays(): void
    {
        $stay = $this->request();

        Livewire::actingAs($this->superAdmin())
            ->test(ListStays::class)
            ->assertCanSeeTableRecords([$stay]);
    }

    /** The default tab is the one thing a member of staff can act on. */
    public function test_the_first_tab_shows_only_what_is_waiting_on_rihla(): void
    {
        $waiting = $this->request();
        $held = app(StayBooking::class)->confirmWithPartner($this->request());

        Livewire::actingAs($this->superAdmin())
            ->test(ListStays::class)
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$held]);
    }

    // ── The decision ─────────────────────────────────────────────────────

    public function test_confirming_takes_the_dates_and_starts_the_clock(): void
    {
        $stay = $this->request();

        Livewire::actingAs($this->superAdmin())
            ->test(ListStays::class)
            ->callTableAction('confirm', $stay)
            ->assertHasNoTableActionErrors();

        $stay->refresh();

        $this->assertSame(Stay::HELD, $stay->status);
        $this->assertNotNull($stay->expires_at);
        $this->assertNotNull($stay->deposit_due_at);
    }

    /**
     * The dates went while the phone was ringing.
     *
     * An ordinary outcome of a real business rather than a fault, so it is
     * a notification and the stay is left exactly as it was — a request
     * somebody can still decline properly, with a reason the guest sees.
     */
    public function test_confirming_dates_that_have_gone_says_so_and_changes_nothing(): void
    {
        $first = $this->request();
        $second = $this->request();

        app(StayBooking::class)->confirmWithPartner($first);

        Livewire::actingAs($this->superAdmin())
            ->test(ListStays::class)
            ->callTableAction('confirm', $second)
            ->assertNotified('Those dates have gone');

        $this->assertSame(Stay::REQUESTED, $second->fresh()->status);
        $this->assertNull($second->fresh()->expires_at);
    }

    public function test_declining_needs_a_reason_the_guest_will_see(): void
    {
        $stay = $this->request();

        Livewire::actingAs($this->superAdmin())
            ->test(ListStays::class)
            ->callTableAction('decline', $stay, ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame(Stay::REQUESTED, $stay->fresh()->status);
    }

    public function test_declining_records_the_reason_and_frees_the_dates(): void
    {
        $stay = $this->request();

        Livewire::actingAs($this->superAdmin())
            ->test(ListStays::class)
            ->callTableAction('decline', $stay, ['reason' => 'The guesthouse is full that week.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(Stay::DECLINED, $stay->fresh()->status);
        $this->assertSame('The guesthouse is full that week.', $stay->fresh()->cancellation_reason);

        // And somebody else can have them.
        $this->assertSame(
            Stay::HELD,
            app(StayBooking::class)->confirmWithPartner($this->request())->status,
        );
    }

    /**
     * Neither button is offered once the decision has been made.
     *
     * A second Confirm on a held stay would be a member of staff being
     * invited to do something with no meaning, and the answer to it would
     * have to be an error message.
     */
    public function test_the_buttons_disappear_once_the_decision_is_made(): void
    {
        $held = app(StayBooking::class)->confirmWithPartner($this->request());

        Livewire::actingAs($this->superAdmin())
            ->test(ListStays::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('confirm', $held)
            ->assertTableActionHidden('decline', $held);
    }

    // ── What the detail screen shows ─────────────────────────────────────

    /**
     * The frozen policy, not the property's current one.
     *
     * Showing today's terms against a stay agreed under last month's is how
     * a dispute gets answered with the wrong number.
     */
    public function test_the_detail_screen_shows_the_terms_the_guest_agreed_to(): void
    {
        $stay = $this->request();

        $this->room->property->update(['deposit_pct' => 80, 'free_cancel_days' => 0]);

        $this->actingAs($this->superAdmin())
            ->get(StayResource::getUrl('view', ['record' => $stay]))
            ->assertOk()
            ->assertSee('30% on confirmation')
            ->assertSee('until 14 days before')
            ->assertDontSee('80% on confirmation');
    }

    /**
     * The register is on the screen, whole — §15.6 (Phase 11).
     *
     * Maldivian law requires it and somebody will ask for it, so it lives
     * where a member of staff already is rather than in a report nobody
     * runs. Unmasked on purpose: the protection is the encrypted column
     * and the permission on this page, not asterisks in front of staff who
     * already have both.
     */
    public function test_the_stay_page_shows_the_guest_register(): void
    {
        $stay = $this->request();

        StayGuest::factory()->lead()->create([
            'stay_id' => $stay->getKey(),
            'full_name' => 'Ibrahim Waheed',
            'nationality' => 'Maldivian',
            'id_type' => StayGuest::NATIONAL_ID,
            'id_number' => 'A123456',
        ]);

        $this->actingAs($this->superAdmin())
            ->get(StayResource::getUrl('view', ['record' => $stay]))
            ->assertOk()
            ->assertSee('Who stayed')
            ->assertSee('Ibrahim Waheed')
            ->assertSee('A123456')
            ->assertSee('Lead guest');
    }

    /** No guests recorded: no empty section pretending there is a register. */
    public function test_a_stay_with_no_register_shows_no_register_section(): void
    {
        $stay = $this->request();

        $this->actingAs($this->superAdmin())
            ->get(StayResource::getUrl('view', ['record' => $stay]))
            ->assertOk()
            ->assertDontSee('Who stayed');
    }

    public function test_the_navigation_badge_counts_only_what_is_waiting(): void
    {
        $this->assertNull(StayResource::getNavigationBadge());

        $this->request();
        $this->assertSame('1', StayResource::getNavigationBadge());

        // A held stay is waiting on the customer's money, not on Rihla.
        app(StayBooking::class)->confirmWithPartner(Stay::sole());
        $this->assertNull(StayResource::getNavigationBadge());
    }
}
