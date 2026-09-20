<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BroadcastDelivery;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\EmergencyBroadcast;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Broadcasts\Broadcaster;
use App\Services\Broadcasts\Channels\EmailChannel;
use App\Services\Broadcasts\Channels\SmsChannel;
use App\Services\Portal\Gatekeeper;
use App\Support\Access;
use App\Support\DepartureReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Safety and emergency — §6.5.
 *
 * The thing these tests exist to stop is a **comfortable lie**: a screen
 * that says a message went out when it went to a log file, or a delivery
 * list that counts attempts as people. On this host there is no SMTP and no
 * SMS provider, and the system has to say so rather than look successful.
 */
class EmergencyBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function departureWithBookings(int $bookings = 2): Departure
    {
        $departure = Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subDays(2),
            'date_end' => now()->addDays(12),
        ]);

        for ($i = 0; $i < $bookings; $i++) {
            $customer = Customer::factory()->create(['email' => "family{$i}@example.test"]);

            $booking = Booking::factory()->create([
                'customer_id' => $customer->getKey(),
                'departure_id' => $departure->getKey(),
            ]);
            $booking->forceFill(['status' => Booking::CONFIRMED])->save();

            $booking->travellers()->create([
                'traveller_id' => Traveller::factory()->for($customer)->create([
                    'emergency_contact_name' => 'Next of kin',
                    'emergency_contact_phone' => '+9607000000',
                ])->getKey(),
                'occupancy' => 'quad',
            ]);
        }

        return $departure->fresh();
    }

    private function broadcast(Departure $departure): EmergencyBroadcast
    {
        return EmergencyBroadcast::create([
            'departure_id' => $departure->getKey(),
            'headline' => 'Everybody is safe.',
            'body' => 'You may have seen the news. The group is accounted for.',
        ]);
    }

    // ── What actually works on this host ─────────────────────────────────

    /**
     * The portal is the only channel that needs nothing, and it is why
     * §6.5's "even a minimal version belongs in the first portal release"
     * is satisfiable now rather than after somebody signs an SMS contract.
     */
    public function test_the_portal_channel_reaches_every_confirmed_booking(): void
    {
        $departure = $this->departureWithBookings(3);
        $broadcast = $this->broadcast($departure);

        app(Broadcaster::class)->send($broadcast, User::factory()->create());

        $delivered = BroadcastDelivery::where('channel', 'portal')
            ->where('status', BroadcastDelivery::DELIVERED)
            ->count();

        $this->assertSame(3, $delivered);
        $this->assertSame(3, $broadcast->fresh()->reached());
    }

    /**
     * `MAIL_MAILER=log` is a valid Laravel setup and a useless emergency
     * channel. A delivery row saying "delivered" for a message that went to
     * a log file is worse than no row.
     */
    public function test_email_reports_itself_unavailable_when_the_mailer_only_logs(): void
    {
        config(['mail.default' => 'log']);

        $channel = new EmailChannel;

        $this->assertFalse($channel->isAvailable());
        $this->assertStringContainsString('log', $channel->whyUnavailable());
    }

    public function test_email_is_unavailable_without_a_from_address(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => null]);

        $channel = new EmailChannel;

        $this->assertFalse($channel->isAvailable());
        $this->assertStringContainsString('bounce', $channel->whyUnavailable());
    }

    public function test_email_sends_when_it_is_really_configured(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'ops@example.test']);
        Mail::fake();

        $departure = $this->departureWithBookings(2);
        app(Broadcaster::class)->send($this->broadcast($departure));

        $this->assertSame(
            2,
            BroadcastDelivery::where('channel', 'email')
                ->where('status', BroadcastDelivery::DELIVERED)
                ->count(),
        );
    }

    /** Nobody has chosen a provider, and guessing one is not a feature. */
    public function test_sms_says_there_is_no_provider(): void
    {
        config(['broadcasts.sms.provider' => null]);

        $channel = new SmsChannel;

        $this->assertFalse($channel->isAvailable());
        $this->assertStringContainsString('No SMS provider', $channel->whyUnavailable());
    }

    /**
     * The reason is recorded against every person, not logged once.
     *
     * The question after an incident is "did her family get this", and it
     * needs an answer per household rather than a global note.
     */
    public function test_an_unavailable_channel_is_written_down_against_everybody(): void
    {
        config(['mail.default' => 'log', 'broadcasts.sms.provider' => null]);

        $departure = $this->departureWithBookings(2);
        app(Broadcaster::class)->send($this->broadcast($departure));

        foreach (['email', 'sms'] as $channel) {
            $rows = BroadcastDelivery::where('channel', $channel)->get();

            $this->assertCount(2, $rows, "[{$channel}] did not record a row per booking.");

            foreach ($rows as $row) {
                $this->assertSame(BroadcastDelivery::UNAVAILABLE, $row->status);
                $this->assertNotEmpty($row->detail, "[{$channel}] recorded no reason.");
            }
        }
    }

    /** One channel missing must not stop the one that works. */
    public function test_a_missing_channel_does_not_stop_the_broadcast(): void
    {
        config(['mail.default' => 'log', 'broadcasts.sms.provider' => null]);

        $departure = $this->departureWithBookings(2);
        $broadcast = $this->broadcast($departure);

        app(Broadcaster::class)->send($broadcast);

        $this->assertTrue($broadcast->fresh()->isSent());
        $this->assertSame(2, $broadcast->fresh()->reached());
    }

    /** "Reached" counts people, not attempts. */
    public function test_reached_counts_bookings_not_delivery_rows(): void
    {
        config(['mail.default' => 'log', 'broadcasts.sms.provider' => null]);

        $departure = $this->departureWithBookings(2);
        $broadcast = $this->broadcast($departure);

        app(Broadcaster::class)->send($broadcast);

        // Six rows: two bookings across three channels.
        $this->assertSame(6, BroadcastDelivery::count());
        $this->assertSame(2, $broadcast->fresh()->reached());
    }

    /** A draft booking is not somebody who is on the trip. */
    public function test_a_draft_booking_is_not_messaged(): void
    {
        $departure = $this->departureWithBookings(1);

        Booking::factory()->create([
            'departure_id' => $departure->getKey(),
            'customer_id' => Customer::factory()->create()->getKey(),
        ]);

        app(Broadcaster::class)->send($this->broadcast($departure));

        $this->assertSame(1, BroadcastDelivery::where('channel', 'portal')->count());
    }

    /**
     * A retry after a partial send updates rather than adding rows, so the
     * delivery list stays a list of people.
     */
    public function test_sending_twice_does_not_double_the_delivery_list(): void
    {
        $departure = $this->departureWithBookings(2);
        $broadcast = $this->broadcast($departure);

        $broadcaster = app(Broadcaster::class);
        $broadcaster->send($broadcast);
        $sentAt = $broadcast->fresh()->sent_at;

        $broadcaster->send($broadcast->fresh());

        $this->assertSame(6, BroadcastDelivery::count());
        // And the first send is when it went out, not the retry.
        $this->assertSame($sentAt->toDateTimeString(), $broadcast->fresh()->sent_at->toDateTimeString());
    }

    // ── What staff are told before they press send ───────────────────────

    public function test_readiness_names_every_channel_and_why_it_cannot_send(): void
    {
        config(['mail.default' => 'log', 'broadcasts.sms.provider' => null]);

        $readiness = collect(app(Broadcaster::class)->readiness())->keyBy('channel');

        $this->assertTrue($readiness['portal']['available']);
        $this->assertFalse($readiness['email']['available']);
        $this->assertFalse($readiness['sms']['available']);

        $this->assertNotEmpty($readiness['email']['why']);
        $this->assertNotEmpty($readiness['sms']['why']);
    }

    // ── It reaches the pages people open ─────────────────────────────────

    public function test_a_sent_broadcast_appears_on_the_pilgrim_portal(): void
    {
        $departure = $this->departureWithBookings(1);
        $booking = $departure->bookings()->sole();

        app(Broadcaster::class)->send($this->broadcast($departure));

        $token = app(Gatekeeper::class)->issue($booking);
        $this->get(route('portal.enter', ['locale' => 'en', 'token' => $token]));

        $this->get(route('portal.home', ['locale' => 'en']))
            ->assertOk()
            ->assertSee('Everybody is safe.');
    }

    public function test_an_unsent_broadcast_appears_nowhere(): void
    {
        $departure = $this->departureWithBookings(1);
        $booking = $departure->bookings()->sole();

        $this->broadcast($departure);

        $token = app(Gatekeeper::class)->issue($booking);
        $this->get(route('portal.enter', ['locale' => 'en', 'token' => $token]));

        $this->get(route('portal.home', ['locale' => 'en']))
            ->assertOk()
            ->assertDontSee('Everybody is safe.');
    }

    // ── Emergency contacts ───────────────────────────────────────────────

    /**
     * The moment this matters is the moment nobody has time to go looking
     * for a phone number.
     */
    public function test_a_traveller_with_nobody_to_ring_blocks_the_departure(): void
    {
        $departure = $this->departureWithBookings(1);

        Traveller::whereNotNull('emergency_contact_name')
            ->update(['emergency_contact_name' => null, 'emergency_contact_phone' => null]);

        $concerns = array_values(array_filter(
            DepartureReadiness::concerns($departure->fresh()),
            fn (array $concern): bool => $concern['area'] === DepartureReadiness::EMERGENCY_CONTACT,
        ));

        $this->assertCount(1, $concerns);
        $this->assertSame(DepartureReadiness::BLOCKING, $concerns[0]['severity']);
        $this->assertTrue(DepartureReadiness::hasBlockers($departure->fresh()));
    }

    public function test_a_phone_number_with_no_name_is_still_not_a_contact(): void
    {
        $departure = $this->departureWithBookings(1);

        Traveller::query()->update(['emergency_contact_name' => null]);

        $concerns = array_filter(
            DepartureReadiness::concerns($departure->fresh()),
            fn (array $concern): bool => $concern['area'] === DepartureReadiness::EMERGENCY_CONTACT,
        );

        $this->assertCount(1, $concerns);
    }

    public function test_everybody_having_one_says_nothing(): void
    {
        $departure = $this->departureWithBookings(2);

        $concerns = array_filter(
            DepartureReadiness::concerns($departure),
            fn (array $concern): bool => $concern['area'] === DepartureReadiness::EMERGENCY_CONTACT,
        );

        $this->assertSame([], $concerns);
    }

    /** An operational policy, not a fact, so it is configuration. */
    public function test_the_check_can_be_turned_off(): void
    {
        config(['broadcasts.require_emergency_contacts' => false]);

        $departure = $this->departureWithBookings(1);
        Traveller::query()->update(['emergency_contact_name' => null, 'emergency_contact_phone' => null]);

        $concerns = array_filter(
            DepartureReadiness::concerns($departure->fresh()),
            fn (array $concern): bool => $concern['area'] === DepartureReadiness::EMERGENCY_CONTACT,
        );

        $this->assertSame([], $concerns);
    }

    // ── Access ───────────────────────────────────────────────────────────

    /**
     * A leader in the middle of an incident is the worst-placed person to
     * decide that forty households should hear about it.
     */
    public function test_the_tour_leader_drafts_but_cannot_send(): void
    {
        $leader = User::factory()->create()->assignRole(Access::TOUR_LEADER);

        $this->assertTrue($leader->can('broadcast.create'));
        $this->assertFalse($leader->can('broadcast.send'));
    }

    public function test_only_operations_can_send(): void
    {
        foreach (Access::ROLES as $role) {
            if (in_array($role, [Access::SUPER_ADMIN, Access::OPERATIONS_MANAGER], true)) {
                continue;
            }

            $this->assertFalse(
                User::factory()->create()->assignRole($role)->can('broadcast.send'),
                "[{$role}] can send an emergency broadcast.",
            );
        }

        $this->assertTrue(
            User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER)->can('broadcast.send'),
        );
    }

    /** What was sent in an emergency is the record of what was said. */
    public function test_nobody_has_a_delete_verb_for_a_broadcast(): void
    {
        $this->assertNotContains('broadcast.delete', Access::PERMISSIONS);
    }
}
