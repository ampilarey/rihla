<?php

namespace Tests\Feature;

use App\Filament\Resources\Notices\NoticeResource;
use App\Filament\Resources\Notices\Pages\ListNotices;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Notice;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Notices\Sweep;
use App\Services\Portal\Gatekeeper;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Notices — §11.2's notifications, as a thing that works today.
 *
 * There is no WhatsApp API, no SMTP and no SMS provider. So the property
 * these tests hold is that the system **observes rather than invents**, and
 * that the chasing list is a list of people who owe something rather than a
 * count of rows.
 */
class NoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults(['locale' => 'en']);
    }

    private function booking(int $daysAway = 60): Booking
    {
        $departure = Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addDays($daysAway),
            'date_end' => now()->addDays($daysAway + 14),
        ]);

        $customer = Customer::factory()->create(['phone' => '+960 777 1234']);

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create()->getKey(),
            'occupancy' => 'quad',
        ]);

        return $booking->fresh();
    }

    // ── It observes rather than invents ──────────────────────────────────

    public function test_a_missing_passport_becomes_a_notice(): void
    {
        $this->booking();

        app(Sweep::class)->run();

        $notice = Notice::where('kind', Notice::DOCUMENT_NEEDED)->sole();

        $this->assertStringContainsString('passport', $notice->headline);
        $this->assertFalse($notice->isHandled());
    }

    public function test_an_approaching_departure_becomes_a_notice(): void
    {
        $this->booking(daysAway: 9);

        app(Sweep::class)->run();

        $notice = Notice::where('kind', Notice::DEPARTURE_SOON)->sole();

        $this->assertStringContainsString('9 days', $notice->headline);
    }

    public function test_a_departure_far_off_does_not(): void
    {
        $this->booking(daysAway: 90);

        app(Sweep::class)->run();

        $this->assertSame(0, Notice::where('kind', Notice::DEPARTURE_SOON)->count());
    }

    public function test_a_finished_trip_raises_nothing(): void
    {
        $departure = Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subMonths(2),
            'date_end' => now()->subMonths(2)->addDays(14),
        ]);

        $booking = Booking::factory()->create(['departure_id' => $departure->getKey()]);
        $booking->forceFill(['status' => Booking::COMPLETED])->save();
        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->create()->getKey(),
            'occupancy' => 'quad',
        ]);

        app(Sweep::class)->run();

        $this->assertSame(0, Notice::count());
    }

    /** A draft booking is not somebody who is going. */
    public function test_a_draft_booking_raises_nothing(): void
    {
        $departure = Departure::factory()->withSeats(20)->create([
            'date_start' => now()->addDays(10),
            'date_end' => now()->addDays(24),
        ]);

        $booking = Booking::factory()->create(['departure_id' => $departure->getKey()]);
        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->create()->getKey(),
            'occupancy' => 'quad',
        ]);

        app(Sweep::class)->run();

        $this->assertSame(0, Notice::count());
    }

    /**
     * Idempotent, and that is what makes it safe as a cron line.
     *
     * Without it a nightly sweep stacks fourteen identical passport
     * reminders and the portal becomes a wall nobody reads.
     */
    public function test_sweeping_repeatedly_does_not_stack_notices(): void
    {
        $this->booking(daysAway: 9);

        $sweep = app(Sweep::class);
        $sweep->run();
        $sweep->run();
        $sweep->run();

        $this->assertSame(1, Notice::where('kind', Notice::DOCUMENT_NEEDED)->count());
        $this->assertSame(1, Notice::where('kind', Notice::DEPARTURE_SOON)->count());
    }

    /** The situation recurring is news; the handled one is history. */
    public function test_a_handled_notice_can_be_raised_again(): void
    {
        $booking = $this->booking();

        app(Sweep::class)->run();
        Notice::sole()->markHandled(User::factory()->create());

        app(Sweep::class)->run();

        $this->assertSame(2, Notice::where('kind', Notice::DOCUMENT_NEEDED)->count());
    }

    // ── Seen is not handled ──────────────────────────────────────────────

    /**
     * Two different facts.
     *
     * A notice disappearing from the staff queue because the customer
     * loaded a page is how a passport request goes unchased for a
     * fortnight.
     */
    public function test_the_customer_opening_the_portal_does_not_clear_the_staff_queue(): void
    {
        $booking = $this->booking();
        app(Sweep::class)->run();

        $token = app(Gatekeeper::class)->issue($booking);
        $this->get(route('portal.enter', ['token' => $token]));
        $this->get(route('portal.home'))->assertOk()->assertSee('We still need a passport');

        $notice = Notice::sole();

        $this->assertNotNull($notice->seen_at);
        $this->assertFalse($notice->isHandled());
        $this->assertSame(1, Notice::needsChasing()->count());
    }

    /**
     * Chasing somebody is not the same as them sending the passport.
     *
     * So a handled notice leaves the *staff* queue and the portal keeps
     * asking while the cause is still true — the portal raises its own on
     * read, and the cause has not gone away.
     */
    public function test_chasing_somebody_does_not_stop_the_portal_asking(): void
    {
        $booking = $this->booking();
        app(Sweep::class)->run();

        Notice::sole()->markHandled();

        $this->assertSame(0, Notice::needsChasing()->count());

        $token = app(Gatekeeper::class)->issue($booking);
        $this->get(route('portal.enter', ['token' => $token]));

        $this->get(route('portal.home'))->assertOk()->assertSee('We still need a passport');
    }

    /** And when the cause goes away, so does the notice. */
    public function test_a_notice_stops_when_the_thing_it_was_about_is_over(): void
    {
        $booking = $this->booking(daysAway: 9);
        app(Sweep::class)->run();

        $soon = Notice::where('kind', Notice::DEPARTURE_SOON)->sole();
        $soon->markHandled();

        // The departure moves out of the reminder window, so there is
        // nothing to say any more.
        $booking->departure->forceFill([
            'date_start' => now()->addDays(90),
            'date_end' => now()->addDays(104),
        ])->save();

        $token = app(Gatekeeper::class)->issue($booking);
        $this->get(route('portal.enter', ['token' => $token]));

        $this->get(route('portal.home'))->assertOk()->assertDontSee('You travel in');
        $this->assertSame(1, Notice::where('kind', Notice::DEPARTURE_SOON)->count());
    }

    /**
     * The portal raises its own, because nobody has confirmed that cron
     * runs on this cPanel account.
     *
     * Unlike a lapsed seat hold — which the next booking reclaims inside
     * its own row lock — a notice that is never raised simply does not
     * exist, so the page that needs it computes it.
     */
    public function test_the_portal_is_right_even_if_the_sweep_never_ran(): void
    {
        $booking = $this->booking();

        $this->assertSame(0, Notice::count());

        $token = app(Gatekeeper::class)->issue($booking);
        $this->get(route('portal.enter', ['token' => $token]));

        $this->get(route('portal.home'))->assertOk()->assertSee('We still need a passport');

        $this->assertSame(1, Notice::where('kind', Notice::DOCUMENT_NEEDED)->count());
    }

    /** Opening the page twice does not stack anything. */
    public function test_opening_the_portal_repeatedly_does_not_stack_notices(): void
    {
        $booking = $this->booking();

        $token = app(Gatekeeper::class)->issue($booking);
        $this->get(route('portal.enter', ['token' => $token]));

        $this->get(route('portal.home'))->assertOk();
        $this->get(route('portal.home'))->assertOk();
        $this->get(route('portal.home'))->assertOk();

        $this->assertSame(1, Notice::where('kind', Notice::DOCUMENT_NEEDED)->count());
    }

    // ── The chasing list ─────────────────────────────────────────────────

    /**
     * The badge counts people who owe us something.
     *
     * "You travel in nine days" needs no chasing, and counting it would be
     * a number nobody can drive to zero.
     */
    public function test_the_badge_counts_only_what_needs_chasing(): void
    {
        $this->booking(daysAway: 9);

        app(Sweep::class)->run();

        $this->assertSame(2, Notice::count());
        $this->assertSame('1', NoticeResource::getNavigationBadge());
    }

    public function test_marking_it_chased_clears_the_badge(): void
    {
        $this->booking();
        app(Sweep::class)->run();

        Livewire::actingAs(User::factory()->create()->assignRole(Access::BOOKING_STAFF))
            ->test(ListNotices::class)
            ->callTableAction('handled', Notice::sole())
            ->assertHasNoTableActionErrors();

        $this->assertTrue(Notice::sole()->isHandled());
        $this->assertNull(NoticeResource::getNavigationBadge());
    }

    /**
     * The bridge while there is no messaging API: the conversation opens
     * with the message already written.
     */
    public function test_the_whatsapp_link_carries_the_message(): void
    {
        $this->booking();
        app(Sweep::class)->run();

        $url = Notice::sole()->whatsappUrl();

        $this->assertStringStartsWith('https://wa.me/9607771234?text=', $url);
        $this->assertStringContainsString(rawurlencode('We still need a passport'), $url);
    }

    /** A button that opens nothing is worse than no button. */
    public function test_a_customer_with_no_number_has_no_whatsapp_link(): void
    {
        $booking = $this->booking();
        $booking->customer->forceFill(['phone' => null])->save();

        app(Sweep::class)->run();

        $this->assertNull(Notice::sole()->fresh()->whatsappUrl());

        Livewire::actingAs(User::factory()->create()->assignRole(Access::BOOKING_STAFF))
            ->test(ListNotices::class)
            ->assertTableActionHidden('whatsapp', Notice::sole());
    }

    public function test_booking_staff_can_work_the_list(): void
    {
        $this->booking();
        app(Sweep::class)->run();

        Livewire::actingAs(User::factory()->create()->assignRole(Access::BOOKING_STAFF))
            ->test(ListNotices::class)
            ->assertOk()
            ->assertSee('We still need a passport');
    }

    public function test_the_content_manager_cannot(): void
    {
        $this->actingAs(User::factory()->create()->assignRole(Access::CONTENT_MANAGER))
            ->get(NoticeResource::getUrl('index'))
            ->assertForbidden();
    }

    // ── Nothing is typed by hand ─────────────────────────────────────────

    /**
     * No create verb, and none is ever added.
     *
     * The moment somebody can write a notice by hand, the portal starts
     * carrying claims nothing backs — the failure that put invented social
     * links on the live site.
     */
    public function test_nobody_can_write_a_notice_by_hand(): void
    {
        $this->assertNotContains('notice.create', Access::PERMISSIONS);
        $this->assertNotContains('notice.delete', Access::PERMISSIONS);

        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $user = User::factory()->create()->assignRole($role);

            $this->assertFalse($user->can('create', Notice::class), "[{$role}] can write a notice.");
        }
    }

    public function test_every_kind_has_a_sentence(): void
    {
        foreach (Notice::KINDS as $kind) {
            $this->assertNotSame('Unknown', (new Notice(['kind' => $kind]))->kindLabel(), $kind);
        }
    }

    public function test_the_command_reports_what_it_raised(): void
    {
        $this->booking(daysAway: 9);

        $this->artisan('notices:sweep')
            ->expectsOutputToContain('Document needed: 1')
            ->assertSuccessful();
    }

    public function test_the_command_says_so_when_there_is_nothing(): void
    {
        $this->artisan('notices:sweep')
            ->expectsOutputToContain('Nothing new to tell anybody.')
            ->assertSuccessful();
    }
}
