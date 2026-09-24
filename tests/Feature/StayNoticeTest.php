<?php

namespace Tests\Feature;

use App\Filament\Resources\Notices\Pages\ListNotices;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Notice;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\Notices\Sweep;
use App\Services\Stays\StayBooking;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A guesthouse customer gets told things too — §15.7.
 *
 * `notices` was the one table in this codebase whose entire job is
 * "somebody needs to be told", and until now it could only hold news about
 * a booking. A stay is not a booking — no departure, no seats, no
 * travellers — so a customer whose partner had said yes, or whose hold was
 * running out with the deposit unpaid, had nowhere for that to be recorded.
 *
 * **Nothing here is sent anywhere.** There is no SMTP on this host and no
 * WhatsApp API behind it, and this file does not pretend otherwise: a
 * notice is a row and a line on the staff queue with the conversation
 * already written. That is the channel that actually exists.
 *
 * Named `StayNoticeTest` beside the existing `NoticeTest`, which covers the
 * booking half — per AGENTS.md, a second suite on one domain says which
 * half it covers.
 */
class StayNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private RoomType $room;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults(['locale' => 'en']);

        $this->property = Property::factory()->create([
            'name' => ['en' => 'Maafushi View'],
            'check_in_instructions' => ['en' => 'The ferry jetty is a five-minute walk.'],
            'check_in_time' => '14:00',
            'currency' => 'USD',
            'min_nights' => 1,
            'balance_days_before' => 14,
        ]);

        $this->room = RoomType::factory()->create([
            'property_id' => $this->property->id,
            'name' => ['en' => 'Sea-facing double'],
            'quantity' => 2,
            'base_rate_minor' => 10_000,
        ]);

        $this->customer = Customer::factory()->create([
            'name' => 'Ibrahim Waheed',
            'phone' => '+960 777 1234',
        ]);
    }

    private function stay(int $daysAway = 60): Stay
    {
        return app(StayBooking::class)->request(
            $this->customer,
            $this->room->fresh(),
            CarbonImmutable::now()->addDays($daysAway)->startOfDay(),
            CarbonImmutable::now()->addDays($daysAway + 2)->startOfDay(),
            adults: 2,
        );
    }

    // ── The four things worth telling a guest ────────────────────────────

    public function test_a_paid_stay_is_told_it_is_booked(): void
    {
        $stay = $this->confirmed(balanceToo: true);

        app(Sweep::class)->run();

        $notice = $stay->notices()->where('kind', Notice::STAY_CONFIRMED)->first();

        $this->assertNotNull($notice);
        $this->assertStringContainsString('Maafushi View', $notice->headline);
        $this->assertStringContainsString($stay->reference, $notice->body);
    }

    /**
     * `held` is *the partner said yes and the deposit is outstanding*, so
     * the good news and the money owed are the same moment — and it gets
     * one notice, not two. A queue that says the same thing twice is one
     * people stop reading.
     */
    public function test_a_hold_with_no_deposit_is_chased_once(): void
    {
        $stay = $this->held();

        $this->assertSame(Stay::HELD, $stay->status);

        app(Sweep::class)->run();

        $this->assertSame(1, $stay->notices()->count());

        $notice = $stay->notices()->where('kind', Notice::DEPOSIT_DUE)->first();

        $this->assertNotNull($notice);
        $this->assertStringContainsString('Sea-facing double', $notice->body);
    }

    /**
     * A hold with no expiry is not a countdown, so it is not described as
     * one. Naming a deadline that does not exist is the same class of
     * invention as a made-up price.
     */
    public function test_a_hold_with_no_expiry_names_no_deadline(): void
    {
        $stay = $this->held();
        $stay->forceFill(['expires_at' => null])->save();

        app(Sweep::class)->run();

        $this->assertStringNotContainsString(
            'runs out',
            (string) $stay->notices()->where('kind', Notice::DEPOSIT_DUE)->first()?->body,
        );
    }

    public function test_the_arrival_notice_carries_the_directions(): void
    {
        $stay = $this->confirmed(daysAway: 3, balanceToo: true);

        app(Sweep::class)->run();

        $notice = $stay->notices()->where('kind', Notice::CHECK_IN_SOON)->first();

        $this->assertNotNull($notice);
        $this->assertStringContainsString('You check in in 3 days', $notice->headline);
        $this->assertStringContainsString('from 14:00', $notice->body);
        $this->assertStringContainsString('ferry jetty', $notice->body);
    }

    /** No directions on file means the dates alone, never invented ones. */
    public function test_a_property_with_no_instructions_invents_none(): void
    {
        $this->property->forceFill(['check_in_instructions' => null])->save();

        $stay = $this->confirmed(daysAway: 2, balanceToo: true);

        app(Sweep::class)->run();

        $body = (string) $stay->notices()->where('kind', Notice::CHECK_IN_SOON)->first()?->body;

        $this->assertStringContainsString('Check-in is', $body);
        $this->assertStringNotContainsString('ferry', $body);
    }

    // ── What it refuses to say ───────────────────────────────────────────

    /**
     * A bill three months early is a notice people learn to scroll past.
     * The balance is raised when it is actually due, not when it exists.
     */
    public function test_the_balance_is_not_chased_before_it_is_due(): void
    {
        $stay = $this->confirmed(daysAway: 90);

        app(Sweep::class)->run();

        $this->assertNull($stay->notices()->where('kind', Notice::BALANCE_DUE)->first());
    }

    public function test_the_balance_is_chased_once_it_is_due(): void
    {
        $stay = $this->confirmed(daysAway: 10);

        app(Sweep::class)->run();

        $notice = $stay->notices()->where('kind', Notice::BALANCE_DUE)->first();

        $this->assertNotNull($notice);
        $this->assertStringContainsString($stay->reference, $notice->body);
    }

    /**
     * A stay that fell through has nothing outstanding — §15.7 gives those
     * to the CRM as a follow-up, which is a different job from telling
     * somebody their room is ready.
     */
    public function test_a_lost_stay_is_told_nothing(): void
    {
        // Declined from `requested`: the partner turned the ask down
        // before anything was held. A held stay cannot be declined at all
        // — it expires or is cancelled — which the state machine refuses.
        $stay = app(StayBooking::class)->decline($this->stay(), 'Full that week.');

        app(Sweep::class)->run();

        $this->assertSame(0, $stay->notices()->count());
    }

    /** A stay already behind us is not news. */
    public function test_a_past_stay_is_swept_over(): void
    {
        $stay = $this->confirmed();
        $stay->forceFill([
            'check_in' => now()->subDays(10)->startOfDay(),
            'check_out' => now()->subDays(8)->startOfDay(),
        ])->save();

        app(Sweep::class)->run();

        $this->assertSame(0, $stay->fresh()->notices()->count());
    }

    /** Running it twice produces one notice, as it does for a booking. */
    public function test_the_sweep_is_idempotent(): void
    {
        $stay = $this->confirmed(balanceToo: true);

        app(Sweep::class)->run();
        app(Sweep::class)->run();

        $this->assertSame(1, $stay->notices()->where('kind', Notice::STAY_CONFIRMED)->count());
    }

    // ── The channel that actually exists ─────────────────────────────────

    /**
     * The whole delivery mechanism: staff tap, and the conversation they
     * were going to have anyway opens with the message already written.
     */
    public function test_the_whatsapp_link_reaches_the_stays_customer(): void
    {
        $stay = $this->confirmed(balanceToo: true);

        app(Sweep::class)->run();

        $url = (string) $stay->notices()->first()?->whatsappUrl();

        $this->assertStringContainsString('wa.me/9607771234', $url);
        $this->assertStringContainsString(rawurlencode('Maafushi View'), $url);
    }

    /**
     * Rendered through Livewire, not fetched over HTTP: the Who column is
     * a closure over a morph relation, and per AGENTS.md a Filament page
     * answers 200 while a closure throws in its own request.
     */
    public function test_a_stay_notice_reaches_the_staff_queue(): void
    {
        $stay = $this->held();

        app(Sweep::class)->run();

        Livewire::actingAs(User::factory()->create()->assignRole(Access::BOOKING_STAFF))
            ->test(ListNotices::class)
            ->assertOk()
            ->assertSee('Ibrahim Waheed')
            ->assertSee($stay->reference);
    }

    /** A booking notice still reads exactly as it did. */
    public function test_the_booking_half_is_unchanged(): void
    {
        $booking = Booking::factory()->create();

        $notice = Notice::raise($booking, Notice::BOOKING_CONFIRMED, 'Your booking is confirmed');

        $this->assertNotNull($notice);
        $this->assertTrue($notice->booking?->is($booking));
        $this->assertSame($booking->reference, $notice->subjectReference());
    }

    // ── The cascade the database can no longer do ────────────────────────

    /**
     * `notices.booking_id` was `cascadeOnDelete`. A polymorphic column
     * carries no constraint, so the database stopped doing that and said
     * nothing — the rows simply start surviving their owner, carrying a
     * headline with a customer's name in it.
     *
     * Restored in `HasNotices`, and asserted for both owners because a
     * trait that only one model uses is a trait that gets dropped from the
     * other.
     */
    public function test_deleting_a_stay_takes_its_notices_with_it(): void
    {
        $stay = $this->confirmed(balanceToo: true);
        app(Sweep::class)->run();

        $this->assertGreaterThan(0, $stay->notices()->count());

        $stay->delete();

        $this->assertSame(0, Notice::where('noticeable_type', Stay::class)->count());
    }

    public function test_deleting_a_booking_takes_its_notices_with_it(): void
    {
        $booking = Booking::factory()->create();
        Notice::raise($booking, Notice::BOOKING_CONFIRMED, 'Your booking is confirmed');

        $this->assertSame(1, $booking->notices()->count());

        $booking->delete();

        $this->assertSame(0, Notice::where('noticeable_type', Booking::class)->count());
    }

    /** The partner says yes: requested → held, with the deposit clock on. */
    private function held(int $daysAway = 60): Stay
    {
        return app(StayBooking::class)->confirmWithPartner($this->stay($daysAway));
    }

    /**
     * The deposit lands: held → confirmed.
     *
     * The paid total is written directly rather than pushed through the
     * ledger, because what is under test is what the sweep reads, not how
     * money arrives — `StayBookingTest` owns that path.
     */
    private function confirmed(int $daysAway = 60, bool $balanceToo = false): Stay
    {
        $stay = $this->held($daysAway);

        $stay->forceFill([
            'status' => Stay::CONFIRMED,
            'paid_minor' => $balanceToo ? $stay->total()->minor : $stay->deposit()->minor,
        ])->save();

        return $stay->fresh();
    }
}
