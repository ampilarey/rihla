<?php

namespace Tests\Feature;

use App\Filament\Resources\Enquiries\Pages\ListEnquiries;
use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\Stays\StayAllocator;
use App\Services\Stays\StayBooking;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A lost stay becomes a follow-up, not a silence — §15.7.
 *
 * Somebody asked for a guesthouse and did not get one: the partner was
 * full, or the deposit window closed while they were asleep in another
 * time zone. Left alone that is a person who wanted to give Rihla money
 * and heard nothing back — and on a line with a handful of properties,
 * every one of them matters.
 */
class LostStayFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $room;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $property = Property::factory()->create([
            'name' => ['en' => 'Maafushi View'],
            'currency' => 'USD',
            'min_nights' => 1,
        ]);

        $this->room = RoomType::factory()->create([
            'property_id' => $property->id,
            'quantity' => 1,
            'base_rate_minor' => 10000,
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'Ibrahim Waheed',
            'phone' => '7771234',
            'email' => 'ibrahim@example.test',
        ]);
    }

    private function request(): Stay
    {
        return app(StayBooking::class)->request(
            $this->customer,
            $this->room->fresh(),
            CarbonImmutable::parse('2027-03-03'),
            CarbonImmutable::parse('2027-03-05'),
            adults: 2,
        );
    }

    // ── The two endings worth a call ─────────────────────────────────────

    public function test_a_declined_stay_becomes_an_enquiry(): void
    {
        $stay = app(StayBooking::class)->decline($this->request(), 'The guesthouse is full that week.');

        $enquiry = Enquiry::where('stay_id', $stay->getKey())->first();

        $this->assertNotNull($enquiry);
        $this->assertSame('Ibrahim Waheed', $enquiry->name);
        $this->assertSame('7771234', $enquiry->phone);
        $this->assertSame(Enquiry::NEW, $enquiry->status);
        $this->assertSame($this->customer->getKey(), $enquiry->customer_id);
        $this->assertSame($this->room->property_id, $enquiry->property_id);
    }

    public function test_an_expired_hold_becomes_an_enquiry(): void
    {
        $stay = $this->request();
        app(StayBooking::class)->confirmWithPartner($stay);

        $stay->forceFill(['expires_at' => now()->subHour()])->save();

        app(StayAllocator::class)->reclaim($this->room->fresh());

        $this->assertSame(Stay::EXPIRED, $stay->fresh()->status);
        $this->assertNotNull(Enquiry::where('stay_id', $stay->getKey())->first());
    }

    /**
     * **Not a cancellation.** The customer changed their mind, and a
     * follow-up call about a holiday somebody deliberately called off is a
     * nuisance rather than a service. That distinction is the whole reason
     * `cancelled` and `declined` are separate statuses.
     */
    public function test_a_cancelled_stay_does_not_become_an_enquiry(): void
    {
        $stay = app(StayAllocator::class)->release($this->request(), Stay::CANCELLED);

        $this->assertSame(Stay::CANCELLED, $stay->fresh()->status);
        $this->assertSame(0, Enquiry::where('stay_id', $stay->getKey())->count());
    }

    public function test_a_confirmed_stay_does_not_become_an_enquiry(): void
    {
        $booking = app(StayBooking::class);
        $stay = $booking->confirmWithPartner($this->request());
        $booking->settle($booking->requestDeposit($stay, 'bank_transfer'));

        $this->assertSame(Stay::CONFIRMED, $stay->fresh()->status);
        $this->assertSame(0, Enquiry::count());
    }

    // ── What the person on the phone gets ────────────────────────────────

    /**
     * The first sentence of the call is "you asked about Maafushi View".
     * Everything a customer asks in the first ten seconds is in front of
     * whoever picks it up, so nobody has to open the stay to answer.
     */
    public function test_the_enquiry_carries_what_the_call_needs(): void
    {
        $stay = app(StayBooking::class)->decline($this->request(), 'The guesthouse is full that week.');

        $enquiry = Enquiry::where('stay_id', $stay->getKey())->firstOrFail();

        $this->assertStringContainsString('Maafushi View', $enquiry->message);
        $this->assertStringContainsString('3 Mar 2027', $enquiry->message);
        $this->assertStringContainsString('2 nights', $enquiry->message);
        $this->assertStringContainsString('USD 200', $enquiry->message);
        $this->assertStringContainsString('The guesthouse is full that week.', $enquiry->message);
        $this->assertSame(2, $enquiry->party_size);
    }

    /** The two endings need two different calls, and the queue says which. */
    public function test_the_next_action_differs_by_how_it_was_lost(): void
    {
        $declined = app(StayBooking::class)->decline($this->request());

        $this->assertStringContainsString(
            'Offer another guesthouse',
            (string) Enquiry::where('stay_id', $declined->getKey())->value('next_action'),
        );

        $held = app(StayBooking::class)->confirmWithPartner($this->request());
        $held->forceFill(['expires_at' => now()->subHour()])->save();
        app(StayAllocator::class)->reclaim($this->room->fresh());

        $this->assertStringContainsString(
            'still want the room',
            (string) Enquiry::where('stay_id', $held->getKey())->value('next_action'),
        );
    }

    /**
     * Dated today, not "some time". A queue with no date on it is a list,
     * and §8.1 exists because a list is what Rihla already had.
     */
    public function test_the_follow_up_is_due_today_rather_than_never(): void
    {
        $stay = app(StayBooking::class)->decline($this->request());

        $this->assertSame(
            now()->toDateString(),
            Enquiry::where('stay_id', $stay->getKey())->value('next_action_at')?->toDateString(),
        );
    }

    /**
     * Unassigned on purpose. Guessing an owner would put it on somebody's
     * list without their knowing; §8.1 is built on an owner being a person
     * who took it.
     */
    public function test_nobody_is_assigned_it_without_taking_it(): void
    {
        $stay = app(StayBooking::class)->decline($this->request());

        $this->assertNull(Enquiry::where('stay_id', $stay->getKey())->value('assigned_to'));
    }

    // ── Not twice ────────────────────────────────────────────────────────

    /**
     * A status written twice must not produce two calls to the same person
     * about the same week.
     */
    public function test_one_lost_stay_produces_one_follow_up(): void
    {
        $stay = app(StayBooking::class)->decline($this->request());

        // Touch the row again the way any later save would.
        $stay->fresh()->forceFill(['status' => Stay::DECLINED])->save();

        $this->assertSame(1, Enquiry::where('stay_id', $stay->getKey())->count());
    }

    /** A stay built as declined in a fixture is not a customer to ring. */
    public function test_a_stay_created_already_declined_rings_nobody(): void
    {
        Stay::factory()->create([
            'room_type_id' => $this->room->id,
            'property_id' => $this->room->property_id,
            'customer_id' => $this->customer->getKey(),
            'status' => Stay::DECLINED,
        ]);

        $this->assertSame(0, Enquiry::count());
    }

    // ── It is reachable from the CRM ─────────────────────────────────────

    public function test_the_enquiry_knows_where_it_came_from(): void
    {
        $stay = app(StayBooking::class)->decline($this->request());

        $enquiry = Enquiry::where('stay_id', $stay->getKey())->firstOrFail();

        $this->assertTrue($enquiry->isFromALostStay());
        $this->assertSame($stay->getKey(), $enquiry->stay?->getKey());
        $this->assertSame('Maafushi View', $enquiry->property?->getTranslation('name', 'en'));
    }

    /** An ordinary enquiry is not from a stay, and says so. */
    public function test_an_ordinary_enquiry_is_not_from_a_stay(): void
    {
        $this->assertFalse(Enquiry::factory()->create()->isFromALostStay());
    }

    // ── What the person picking it up actually sees ──────────────────────

    /**
     * The board names the guesthouse.
     *
     * A follow-up from a lost stay has no package, and the About column
     * read `package.title`, so before this it said "Not sure yet" — about
     * an enquiry where the person named the property, the dates and the
     * party size. Rendered through Livewire rather than fetched over HTTP,
     * because the column is a closure and a Filament page answers 200
     * while a closure throws in its own request.
     */
    public function test_the_board_names_the_guesthouse_and_says_where_it_came_from(): void
    {
        app(StayBooking::class)->decline($this->request(), 'Full that week.');

        Livewire::actingAs(User::factory()->create()->assignRole(Access::BOOKING_STAFF))
            ->test(ListEnquiries::class)
            ->assertOk()
            ->assertSee('Maafushi View')
            ->assertSee('A guesthouse ask that fell through');
    }

    /** An ordinary enquiry is untouched by any of it. */
    public function test_an_ordinary_enquiry_still_reads_the_same(): void
    {
        Enquiry::factory()->create(['name' => 'Aishath Nazaahath']);

        Livewire::actingAs(User::factory()->create()->assignRole(Access::BOOKING_STAFF))
            ->test(ListEnquiries::class)
            ->assertOk()
            ->assertSee('Aishath Nazaahath')
            ->assertDontSee('A guesthouse ask that fell through');
    }
}
