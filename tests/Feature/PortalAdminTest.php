<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\PortalAccess;
use App\Models\Traveller;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Issuing a portal link from the booking screen.
 *
 * The link is the customer's credential, so the interesting assertions are
 * about what the screen does *not* leave lying around.
 */
class PortalAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function booking(): Booking
    {
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(10)->create([
                'date_start' => now()->addDays(60),
                'date_end' => now()->addDays(74),
            ])->getKey(),
            'seats' => 1,
        ]);

        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create()->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        return $booking->refresh();
    }

    /**
     * Minted when the modal is opened, not when the row is drawn.
     *
     * A link generated while rendering would put a working credential for
     * every booking into the HTML of the bookings list.
     */
    public function test_no_link_exists_until_somebody_asks_for_one(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->assertOk()
            ->assertActionVisible('portalLink');

        $this->assertSame(0, PortalAccess::count());
    }

    public function test_opening_the_modal_mints_a_working_link(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->mountAction('portalLink');

        $this->assertSame(1, PortalAccess::count());

        $access = PortalAccess::sole();

        $this->assertSame($booking->getKey(), $access->booking_id);
        $this->assertTrue($access->isLive());
        // Sixty-four hex characters: a SHA-256, not a token.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $access->token_hash);
    }

    /**
     * The row carries the hash and nothing else.
     *
     * Not a proof that the plaintext never reached the page — it did, once,
     * which is the point — but a proof that nothing readable was kept.
     */
    public function test_nothing_readable_is_kept_afterwards(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->mountAction('portalLink');

        $stored = json_encode(PortalAccess::sole()->getAttributes());

        $this->assertStringNotContainsString('/portal/enter/', (string) $stored);
    }

    /**
     * Gated on `booking.update`, which is also what gates the page itself.
     *
     * So a read-only role never reaches the button — it cannot open the
     * screen, and testing with one would fail on authorisation before
     * reaching the guard, which looks like the guard working while proving
     * nothing. This revokes the single permission and leaves the rest.
     *
     * No new permission for issuing a link: every role that can change a
     * booking already sees everything the portal shows, so there is nothing
     * here to escalate to.
     */
    public function test_the_button_is_gated_on_being_able_to_change_the_booking(): void
    {
        $booking = $this->booking();

        $without = $this->staff(Access::BOOKING_STAFF);
        $without->removeRole(Access::BOOKING_STAFF);
        $without->givePermissionTo(['admin.access', 'booking.viewAny', 'booking.view']);

        // Without booking.update the edit screen is closed entirely, which
        // is the real answer to "can they issue a link".
        $this->actingAs($without->fresh())
            ->get(BookingResource::getUrl('edit', ['record' => $booking]))
            ->assertForbidden();

        $this->assertSame(0, PortalAccess::count());
    }
}
