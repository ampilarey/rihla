<?php

namespace Tests\Feature;

use App\Exceptions\CalendarFeedRefused;
use App\Exceptions\RoomNotAvailable;
use App\Filament\Host\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\RelationManagers\CalendarFeedsRelationManager;
use App\Models\BlockedDate;
use App\Models\CalendarFeed;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Stays\Availability;
use App\Services\Stays\CalendarFetcher;
use App\Services\Stays\CalendarImport;
use App\Support\Access;
use App\Support\HostRole;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Other sites' calendars, imported as blocked nights — §16 Phase 16.
 */
class CalendarImportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://calendar.example.com/ical/room-4.ics';

    private Partner $host;

    private RoomType $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2027-03-01 09:00'));
        CalendarFetcher::resolveUsing(fn (string $host): array => ['93.184.216.34']);

        $this->host = Partner::factory()->create();
        $property = Property::factory()->create(['partner_id' => $this->host->id]);
        $this->room = RoomType::factory()->create(['property_id' => $property->id, 'quantity' => 1]);
    }

    protected function tearDown(): void
    {
        CalendarFetcher::resolveUsing(null);

        parent::tearDown();
    }

    /** @param  list<array{0: string, 1: ?string}>  $events */
    private function ics(array $events, string $extra = ''): string
    {
        $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";

        foreach ($events as [$start, $end]) {
            $body .= "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:{$start}\r\n".($end ? "DTEND;VALUE=DATE:{$end}\r\n" : '')."SUMMARY:Reserved\r\nEND:VEVENT\r\n";
        }

        return $body.$extra."END:VCALENDAR\r\n";
    }

    private function feed(): CalendarFeed
    {
        $feed = new CalendarFeed(['room_type_id' => $this->room->id, 'label' => 'Airbnb', 'url' => self::URL]);
        $feed->save();

        return $feed;
    }

    /** @return list<string> */
    private function blocked(?string $source = null): array
    {
        return BlockedDate::query()->where('room_type_id', $this->room->id)
            ->when($source, fn ($query) => $query->where('source', $source))
            ->orderBy('date')->pluck('date')
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())->all();
    }

    // ── Reading a file ───────────────────────────────────────────────────

    public function test_the_nights_an_ical_file_names(): void
    {
        $ics = "BEGIN:VCALENDAR\r\n"
            // Folded line (RFC 5545 §3.1), a date-time value, DTEND the morning after.
            ."BEGIN:VEVENT\r\nDTSTART:20270310T140000Z\r\nDTEND:20270312T100000Z\r\nSUMMARY:A long\r\n  summary\r\nEND:VEVENT\r\n"
            // No DTEND: one night.
            ."BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20270320\r\nEND:VEVENT\r\n"
            // Cancelled: nothing.
            ."BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20270401\r\nDTEND;VALUE=DATE:20270403\r\nSTATUS:CANCELLED\r\nEND:VEVENT\r\n"
            // Began in the past: only its nights from today.
            ."BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20270227\r\nDTEND;VALUE=DATE:20270302\r\nEND:VEVENT\r\n"
            // A year and a half long: read as a mistake, not honoured.
            ."BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20270501\r\nDTEND;VALUE=DATE:20281101\r\nEND:VEVENT\r\n"
            ."END:VCALENDAR\r\n";

        $this->assertSame(
            ['2027-03-01', '2027-03-10', '2027-03-11', '2027-03-20'],
            app(CalendarImport::class)->nights($ics),
        );
    }

    // ── Syncing ──────────────────────────────────────────────────────────

    public function test_a_sync_closes_the_nights_and_the_next_one_reopens_what_was_dropped(): void
    {
        Http::fake(['calendar.example.com/*' => Http::sequence()
            ->push($this->ics([['20270310', '20270313']]))
            ->push($this->ics([['20270310', '20270311']])),
        ]);
        $feed = $this->feed();

        $this->assertSame(3, app(CalendarImport::class)->sync($feed));
        $this->assertSame(['2027-03-10', '2027-03-11', '2027-03-12'], $this->blocked(BlockedDate::ICAL));

        $this->expectException(RoomNotAvailable::class);

        try {
            app(Availability::class)->assertAvailable($this->room, CarbonImmutable::parse('2027-03-11'), CarbonImmutable::parse('2027-03-13'));
        } finally {
            $this->assertSame(1, app(CalendarImport::class)->sync($feed->fresh()));
            $this->assertSame(['2027-03-10'], $this->blocked(BlockedDate::ICAL));
        }
    }

    /** A night closed by hand is the hand's: never taken over, never reopened. */
    public function test_a_hand_block_is_left_alone(): void
    {
        BlockedDate::create(['room_type_id' => $this->room->id, 'date' => '2027-03-11', 'source' => BlockedDate::ADMIN, 'note' => 'Painting']);
        BlockedDate::create(['room_type_id' => $this->room->id, 'date' => '2027-03-20', 'source' => BlockedDate::PARTNER]);
        Http::fake(['calendar.example.com/*' => Http::sequence()
            ->push($this->ics([['20270310', '20270313']]))
            ->push($this->ics([])),
        ]);
        $feed = $this->feed();

        app(CalendarImport::class)->sync($feed);

        $this->assertSame(['2027-03-10', '2027-03-12'], $this->blocked(BlockedDate::ICAL));
        $this->assertSame(['2027-03-10', '2027-03-11', '2027-03-12', '2027-03-20'], $this->blocked());
        $this->assertSame('Painting', BlockedDate::whereDate('date', '2027-03-11')->sole()->note);

        // The other site's bookings end; the hand's blocks do not.
        app(CalendarImport::class)->sync($feed->fresh());
        $this->assertSame(['2027-03-11', '2027-03-20'], $this->blocked());
    }

    /** The other site being down for a minute must not sell rooms that are taken. */
    public function test_a_failed_read_keeps_the_last_good_blocks_and_says_why(): void
    {
        Http::fake(['calendar.example.com/*' => Http::sequence()
            ->push($this->ics([['20270310', '20270312']]))
            ->push('Service unavailable', 503),
        ]);
        $feed = $this->feed();
        app(CalendarImport::class)->sync($feed);

        $this->assertNull(app(CalendarImport::class)->sync($feed->fresh()));

        $this->assertSame(['2027-03-10', '2027-03-11'], $this->blocked(BlockedDate::ICAL));
        $this->assertSame('The calendar answered 503.', $feed->fresh()->last_error);
    }

    public function test_a_room_type_with_several_rooms_cannot_take_a_calendar(): void
    {
        $this->room->update(['quantity' => 3]);
        Http::fake(['calendar.example.com/*' => Http::response($this->ics([['20270310', '20270312']]))]);
        $feed = $this->feed();

        $this->assertNull(app(CalendarImport::class)->sync($feed));
        $this->assertSame([], $this->blocked());
        $this->assertStringContainsString('one room', (string) $feed->fresh()->last_error);
    }

    public function test_the_link_is_ciphertext_at_rest(): void
    {
        $feed = $this->feed();
        $raw = (string) DB::table('calendar_feeds')->where('id', $feed->id)->value('url');

        $this->assertStringNotContainsString('room-4', $raw);
        $this->assertSame(self::URL, $feed->fresh()->url);
        $this->assertSame('calendar.example.com/…', $feed->maskedUrl());
    }

    // ── Where the server may be sent ─────────────────────────────────────

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function refusedLinks(): array
    {
        return [
            'plain http' => ['http://calendar.example.com/a.ics', ['93.184.216.34']],
            'another port' => ['https://calendar.example.com:8443/a.ics', ['93.184.216.34']],
            'a password' => ['https://user:pw@calendar.example.com/a.ics', ['93.184.216.34']],
            'loopback' => ['https://calendar.example.com/a.ics', ['127.0.0.1']],
            'private network' => ['https://calendar.example.com/a.ics', ['10.0.0.5']],
            'cloud metadata' => ['https://calendar.example.com/a.ics', ['169.254.169.254']],
            'carrier-grade NAT' => ['https://calendar.example.com/a.ics', ['100.64.0.1']],
            'one public, one private' => ['https://calendar.example.com/a.ics', ['93.184.216.34', '192.168.1.1']],
            'nowhere' => ['https://calendar.example.com/a.ics', []],
        ];
    }

    /** @param  list<string>  $addresses */
    #[DataProvider('refusedLinks')]
    public function test_a_link_the_server_must_not_follow_is_refused(string $url, array $addresses): void
    {
        CalendarFetcher::resolveUsing(fn (): array => $addresses);
        Http::fake();

        try {
            app(CalendarFetcher::class)->fetch($url);
            $this->fail('Fetched '.$url);
        } catch (CalendarFeedRefused) {
        }

        Http::assertNothingSent();
    }

    public function test_a_redirect_is_not_followed(): void
    {
        Http::fake(['calendar.example.com/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data'])]);

        $this->expectExceptionMessage('redirects');
        app(CalendarFetcher::class)->fetch(self::URL);
    }

    public function test_something_that_is_not_a_calendar_is_refused(): void
    {
        Http::fake(['calendar.example.com/*' => Http::response('<html>Log in</html>')]);

        $this->expectExceptionMessage('not a calendar');
        app(CalendarFetcher::class)->fetch(self::URL);
    }

    /**
     * The size is checked while the feed downloads, not after it has all
     * been held in memory — security review of §16. Measured against a
     * local server streaming 50 MB: stopped at the limit with the guard,
     * read in full without it.
     */
    public function test_a_feed_is_cut_off_as_soon_as_it_passes_the_limit(): void
    {
        Http::fake(function ($request, array $options) {
            $this->assertIsCallable($options['progress'] ?? null, 'The fetch carries no size guard.');

            return Http::response("BEGIN:VCALENDAR\nEND:VCALENDAR\n");
        });

        app(CalendarFetcher::class)->fetch(self::URL);

        $guard = CalendarFetcher::stopPast(CalendarFetcher::MAX_BYTES);
        $guard(0, CalendarFetcher::MAX_BYTES);           // at the limit: fine

        $this->expectExceptionMessage('too large');
        $guard(0, CalendarFetcher::MAX_BYTES + 1);
    }

    /** A declared length past the limit is refused before a byte is read. */
    public function test_a_feed_declaring_too_much_is_refused_at_once(): void
    {
        $this->expectExceptionMessage('too large');
        CalendarFetcher::stopPast(CalendarFetcher::MAX_BYTES)(CalendarFetcher::MAX_BYTES + 1, 0);
    }

    // ── The host adds one ────────────────────────────────────────────────

    public function test_a_host_adds_a_calendar_and_it_is_read_at_once_and_removing_it_reopens_the_nights(): void
    {
        Http::fake(['calendar.example.com/*' => Http::response($this->ics([['20270310', '20270312']]))]);

        $owner = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $owner->id, 'role' => HostRole::OWNER, 'accepted_at' => now()]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);

        $manager = Livewire::actingAs($owner)
            ->test(CalendarFeedsRelationManager::class, ['ownerRecord' => $this->room->property, 'pageClass' => EditListing::class])
            ->callTableAction('create', data: ['room_type_id' => $this->room->id, 'label' => 'Airbnb', 'url' => self::URL])
            ->assertHasNoTableActionErrors();

        $feed = CalendarFeed::sole();
        $this->assertSame(2, $feed->nights_blocked);
        $this->assertTrue($owner->can('update', $feed));

        $manager->callAction(TestAction::make('delete')->table($feed));
        $this->assertSame([], $this->blocked());
    }

    public function test_the_form_refuses_a_private_address(): void
    {
        CalendarFetcher::resolveUsing(fn (): array => ['10.1.2.3']);
        $this->actingAs(User::factory()->create()->assignRole(Access::SUPER_ADMIN));
        Filament::setCurrentPanel(Filament::getPanel('staff'));

        Livewire::test(CalendarFeedsRelationManager::class, ['ownerRecord' => $this->room->property, 'pageClass' => EditProperty::class])
            ->callTableAction('create', data: ['room_type_id' => $this->room->id, 'label' => 'Airbnb', 'url' => 'https://intranet.example.com/a.ics'])
            ->assertHasTableActionErrors(['url']);

        $this->assertSame(0, CalendarFeed::count());
    }

    public function test_the_command_reads_every_feed(): void
    {
        Http::fake(['calendar.example.com/*' => Http::response($this->ics([['20270310', '20270311']]))]);
        $this->feed();

        $this->artisan('stays:calendars')->expectsOutputToContain('Synced 1 calendar(s); 0 could not be read.')->assertSuccessful();
        $this->assertSame(['2027-03-10'], $this->blocked(BlockedDate::ICAL));
    }
}
