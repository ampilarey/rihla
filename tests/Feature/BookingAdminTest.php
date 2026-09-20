<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Package;
use App\Models\PriceTier;
use App\Models\User;
use App\Services\Booking\SeatAllocator;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen that makes the booking flow usable.
 *
 * Before it, the public checkout wrote rows nobody at Rihla could see: the
 * customer got a reference and a WhatsApp message, and finding out what they
 * had booked meant opening a database client. Three of the nine staff roles
 * existed on paper with no work to do.
 */
class BookingAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departure(int $seats = 10): Departure
    {
        $package = Package::factory()->create(['title' => ['en' => 'Shawwal Umrah']]);

        $departure = Departure::factory()->withSeats($seats)->create([
            'package_id' => $package->getKey(),
            'date_start' => now()->addDays(60),
            'date_end' => now()->addDays(74),
        ]);

        PriceTier::create([
            'departure_id' => $departure->getKey(), 'occupancy' => 'quad', 'amount_minor' => 2_850_000,
        ]);

        return $departure;
    }

    /** A booking with its seats actually held, the way checkout leaves it. */
    private function heldBooking(int $seats = 2, ?Departure $departure = null): Booking
    {
        $departure ??= $this->departure();

        $booking = Booking::factory()->create([
            'customer_id' => Customer::factory()->create(['name' => 'Aminath', 'phone' => '7771234'])->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => $seats,
        ]);

        app(SeatAllocator::class)->hold($departure, $seats, $booking);
        $booking->transitionTo(Booking::HELD);

        return $booking->refresh();
    }

    // ── Who may look ──────────────────────────────────────────────────────

    public function test_booking_staff_can_list_bookings(): void
    {
        $booking = $this->heldBooking();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListBookings::class)
            ->assertCanSeeTableRecords([$booking])
            ->assertSee('Aminath');
    }

    /**
     * Passport numbers and phone numbers are not content, and nobody reaches
     * them by being the person who edits the website.
     */
    public function test_the_content_manager_cannot_reach_bookings(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get('/staff/bookings')
            ->assertForbidden();
    }

    public function test_the_content_manager_holds_no_booking_permission(): void
    {
        $editor = $this->staff(Access::CONTENT_MANAGER);

        foreach (['viewAny', 'view', 'update'] as $action) {
            $this->assertFalse($editor->can("booking.{$action}"));
            $this->assertFalse($editor->can("customer.{$action}"));
        }
    }

    /**
     * viewAny without view — which is the whole reason those are separate
     * permissions. Reporting sees that bookings exist and how many; it does
     * not open one and read a passport number.
     */
    public function test_reporting_may_count_bookings_but_not_open_one(): void
    {
        $reporting = $this->staff(Access::REPORTING);

        $this->assertTrue($reporting->can('booking.viewAny'));
        $this->assertFalse($reporting->can('booking.view'));
        $this->assertFalse($reporting->can('booking.update'));
    }

    public function test_finance_and_pilgrim_support_may_read_but_not_change(): void
    {
        foreach ([Access::FINANCE, Access::PILGRIM_SUPPORT] as $role) {
            $user = $this->staff($role);

            $this->assertTrue($user->can('booking.view'), "[{$role}] should read bookings.");
            $this->assertFalse($user->can('booking.update'), "[{$role}] should not change them.");
        }
    }

    /** A booking is created by the checkout and never deleted. */
    public function test_nobody_may_create_or_delete_a_booking(): void
    {
        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $user = $this->staff($role);

            $this->assertFalse($user->can('booking.create'), "[{$role}] may create bookings.");
            $this->assertFalse($user->can('booking.delete'), "[{$role}] may delete bookings.");
        }
    }

    // ── Confirming ────────────────────────────────────────────────────────

    public function test_confirming_moves_the_seats_and_records_who(): void
    {
        $departure = $this->departure(10);
        $booking = $this->heldBooking(2, $departure);
        $staff = $this->staff(Access::BOOKING_STAFF);

        Livewire::actingAs($staff)
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->callAction('confirm', ['reason' => 'Bank transfer received']);

        $booking->refresh();
        $departure->refresh();

        $this->assertSame(Booking::CONFIRMED, $booking->status);
        $this->assertSame(0, $departure->capacity_held);
        $this->assertSame(2, $departure->capacity_confirmed);

        // `$booking->transitions` and not `transitions()->latest('id')`:
        // the relation already carries an ordering, and a latest() bolted
        // onto it sorts *after* that clause, so the "newest" row comes back
        // as the oldest. The relation is ordered oldest-first on purpose —
        // it is a history — so the newest is simply the last.
        $transition = $booking->transitions->last();

        $this->assertSame('Bank transfer received', $transition->reason);
        $this->assertSame($staff->getKey(), $transition->user_id);
    }

    /**
     * The hold that lapsed at 15:00 and the payment that landed at 15:01,
     * with somebody else having taken the seats in between.
     *
     * Nothing has to notice this on a timer: the next booking to touch the
     * departure reclaims the lapsed hold inside the same row lock, and a
     * booking that was holding those seats is expired in the same breath. So
     * by the time staff open it, the booking says expired and Confirm is not
     * offered — which is the honest answer, because those seats now belong
     * to somebody else.
     *
     * The allocator still refuses a confirm it cannot honour (see
     * SeatAllocationTest); this asserts the operator never gets that far.
     */
    public function test_a_booking_whose_seats_were_taken_cannot_be_confirmed(): void
    {
        $departure = $this->departure(2);
        $booking = $this->heldBooking(2, $departure);

        $this->travel(16)->minutes();

        // Somebody else books the departure, which reclaims the lapsed hold.
        app(SeatAllocator::class)->hold($departure->refresh(), 2);

        $this->assertSame(Booking::EXPIRED, $booking->fresh()->status);

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->assertActionHidden('confirm');

        $departure->refresh();

        $this->assertSame(2, $departure->capacity_held, 'The seats belong to the other booking now.');
        $this->assertSame(0, $departure->capacity_confirmed);
    }

    // ── Cancelling ────────────────────────────────────────────────────────

    public function test_cancelling_a_held_booking_returns_the_seats(): void
    {
        $departure = $this->departure(10);
        $booking = $this->heldBooking(3, $departure);

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->callAction('cancel', ['reason' => 'Changed their mind']);

        $this->assertSame(Booking::CANCELLED, $booking->fresh()->status);
        $this->assertSame(0, $departure->fresh()->capacity_held);
        $this->assertSame(10, $departure->fresh()->seats_remaining);
    }

    /**
     * Confirmed seats live in a different counter from held ones.
     * Subtracting a cancellation from the wrong one would leave the
     * departure permanently, invisibly full — and `capacity_held` cannot go
     * below zero, so it would not even show up as a negative number.
     */
    public function test_cancelling_a_confirmed_booking_returns_the_seats(): void
    {
        $departure = $this->departure(10);
        $booking = $this->heldBooking(3, $departure);
        $staff = $this->staff(Access::BOOKING_STAFF);

        Livewire::actingAs($staff)
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->callAction('confirm', ['reason' => 'Paid']);

        Livewire::actingAs($staff)
            ->test(EditBooking::class, ['record' => $booking->fresh()->getKey()])
            ->callAction('cancel', ['reason' => 'Passport expired']);

        $departure->refresh();

        $this->assertSame(Booking::CANCELLED, $booking->fresh()->status);
        $this->assertSame(0, $departure->capacity_confirmed);
        $this->assertSame(0, $departure->capacity_held);
        $this->assertSame(10, $departure->seats_remaining);
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $booking = $this->heldBooking();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->callAction('cancel', ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(Booking::HELD, $booking->fresh()->status);
    }

    // ── Extending a hold ──────────────────────────────────────────────────

    public function test_extending_a_hold_gives_a_new_expiry_without_taking_more_seats(): void
    {
        $departure = $this->departure(10);
        $booking = $this->heldBooking(2, $departure);

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->callAction('extendHold', ['reason' => 'Bank transfer tomorrow']);

        $departure->refresh();

        $this->assertSame(2, $departure->capacity_held,
            'Extending must not take a second set of seats.');
        $this->assertTrue(
            $booking->seatHolds()->live()->sole()->expires_at->gt(now()->addHours(12)),
            'The new hold should run well past the fifteen-minute checkout window.',
        );
    }

    public function test_the_old_hold_is_released_when_one_is_extended(): void
    {
        $departure = $this->departure(10);
        $booking = $this->heldBooking(2, $departure);
        $original = $booking->seatHolds()->sole();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->callAction('extendHold', ['reason' => 'Waiting on payment']);

        $this->assertNotNull($original->fresh()->released_at);
        $this->assertSame(1, $booking->seatHolds()->live()->count());
    }

    /** Extending on a sold-out departure must not oversell it. */
    public function test_extending_cannot_oversell(): void
    {
        $departure = $this->departure(2);
        $booking = $this->heldBooking(2, $departure);

        // Every seat is now held by this booking; extending needs two more
        // before the old hold goes back, which the departure cannot give.
        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->callAction('extendHold', ['reason' => 'Try it']);

        $departure->refresh();

        $this->assertSame(2, $departure->capacity_held);
        $this->assertLessThanOrEqual(
            $departure->capacity_total,
            $departure->capacity_held + $departure->capacity_confirmed,
        );
    }

    // ── The screen itself ─────────────────────────────────────────────────

    /**
     * A Filament page can throw while the request still returns 200 — each
     * card and action loads in its own Livewire request. Rendering it is the
     * only thing that proves the schema resolves.
     */
    public function test_the_booking_page_renders(): void
    {
        $booking = $this->heldBooking();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->assertOk()
            ->assertSee($booking->reference)
            ->assertSee('Aminath')
            ->assertSee('MVR');
    }

    /**
     * Found by rendering the page: a booking whose hold had lapsed showed
     * "No seats held" and, beside it, "Expires 5 minutes ago" — which reads
     * as a deadline somebody still has time to meet. The expiry belongs to a
     * hold that is live, or to nothing.
     */
    public function test_a_lapsed_hold_shows_no_expiry(): void
    {
        $booking = $this->heldBooking(2);

        $this->travel(30)->minutes();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->assertOk()
            ->assertSee('No seats held')
            // ' ago' rather than 'minutes ago': the first version of this
            // assertion travelled sixteen minutes past a fifteen-minute hold,
            // so Carbon said "1 minute ago" — singular — and the test passed
            // with the defect in place. Any past time here is wrong.
            ->assertDontSee(' ago');
    }

    public function test_the_list_has_no_create_action(): void
    {
        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(ListBookings::class)
            ->assertOk()
            ->assertDontSee('New booking');
    }
}
