<?php

namespace Tests\Feature;

use App\Exceptions\DeskRefusal;
use App\Exceptions\RoomNotAvailable;
use App\Filament\Host\Pages\Calendar;
use App\Filament\Host\Pages\Housekeeping;
use App\Filament\Host\Pages\NewBooking;
use App\Filament\Host\Widgets\Today;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\Stays\Commission;
use App\Services\Stays\DirectBooking;
use App\Support\HostRole;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The rest of a host's desk — direct bookings, the calendar, housekeeping
 * and today's numbers. §16.6, §16.10, §16 Phase 14.3.
 */
class HostDeskTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private User $owner;

    private Property $property;

    private RoomType $room;

    private PropertyUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2027-02-10 09:00'));

        $this->host = Partner::factory()->commissionBased(15)->create();
        $this->owner = $this->member($this->host, HostRole::OWNER);

        $this->property = Property::factory()->create([
            'partner_id' => $this->host->id,
            'currency' => 'USD',
            'min_nights' => 1,
            'deposit_pct' => 30,
        ]);
        $this->room = RoomType::factory()->create([
            'property_id' => $this->property->id,
            'name' => ['en' => 'Garden Double'],
            'quantity' => 1,
            'sleeps' => 2,
            'base_rate_minor' => 10000,
        ]);
        $this->unit = PropertyUnit::factory()->create(['room_type_id' => $this->room->id, 'label' => 'Room 4']);
    }

    private function member(Partner $host, string $role): User
    {
        $user = User::factory()->create();
        HostMembership::create(['partner_id' => $host->id, 'user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    private function inPanel(?User $as = null): void
    {
        $this->actingAs($as ?? $this->owner);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);
    }

    /** @param array<string, mixed> $overrides */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'room_type_id' => $this->room->id,
            'check_in' => '2027-03-01',
            'check_out' => '2027-03-04',
            'adults' => 2,
            'children' => 0,
            'name' => 'Walk-in Guest',
            'phone' => '+960 777 1234',
            'citizenship' => 'visitor',
            'source' => 'walk_in',
            'deposit_method' => Payment::CASH,
        ];
    }

    // ── Direct bookings ──────────────────────────────────────────────────

    public function test_a_direct_booking_is_confirmed_at_once_and_carries_no_commission(): void
    {
        $this->inPanel();

        Livewire::actingAs($this->owner)
            ->test(NewBooking::class)
            ->fillForm($this->form(['unit_id' => $this->unit->id, 'deposit' => 50, 'agreed_total' => 250]))
            ->call('save')
            ->assertHasNoFormErrors();

        $stay = Stay::sole();

        $this->assertSame(Stay::CONFIRMED, $stay->status);
        $this->assertSame(Stay::VIA_HOST, $stay->created_via);
        $this->assertSame($this->owner->id, $stay->created_by);
        $this->assertSame('walk_in', $stay->source);
        $this->assertNull($stay->commission_minor, 'Not brought by the marketplace: no commission.');
        $this->assertSame($this->unit->id, $stay->unit_id);

        // The agreed price replaces the quote, and the quote is kept.
        $this->assertSame(25000, $stay->total_minor);
        $this->assertSame(30000, $stay->rate_snapshot['agreed']['quoted_total_minor']);

        // The deposit was taken by the host, at the desk.
        $payment = Payment::sole();
        $this->assertSame(Payment::COLLECTED_BY_HOST, $payment->collected_by);
        $this->assertSame(5000, $stay->paidToHost()->minor);
    }

    /** The same lock and the same sentence as the marketplace. */
    public function test_a_direct_booking_cannot_take_a_room_the_marketplace_sold(): void
    {
        Stay::factory()->confirmed()->create([
            'property_id' => $this->property->id,
            'room_type_id' => $this->room->id,
            'check_in' => '2027-03-02',
            'check_out' => '2027-03-05',
            'source' => Commission::MARKETPLACE,
        ]);

        $this->inPanel();

        Livewire::actingAs($this->owner)
            ->test(NewBooking::class)
            ->fillForm($this->form())
            ->call('save')
            ->assertNotified('Not free for those dates');

        $this->assertSame(1, Stay::count());

        $this->expectException(RoomNotAvailable::class);
        app(DirectBooking::class)->take($this->host, $this->room, CarbonImmutable::parse('2027-03-01'), CarbonImmutable::parse('2027-03-04'), ['name' => 'X', 'phone' => '1'], adults: 1);
    }

    public function test_a_host_cannot_book_somebody_elses_room_or_call_it_marketplace(): void
    {
        $theirs = RoomType::factory()->create();

        try {
            app(DirectBooking::class)->take($this->host, $theirs, now()->addMonth(), now()->addMonth()->addDay(), ['name' => 'X', 'phone' => '1'], adults: 1);
            $this->fail('Booked a room belonging to another host.');
        } catch (DeskRefusal) {
        }

        try {
            app(DirectBooking::class)->take($this->host, $this->room, now()->addMonth(), now()->addMonth()->addDay(), ['name' => 'X', 'phone' => '1'], adults: 1, source: Commission::MARKETPLACE);
            $this->fail('A direct booking was marked as marketplace.');
        } catch (DeskRefusal $refusal) {
            $this->assertSame(DirectBooking::NOT_MARKETPLACE, $refusal->getMessage());
        }

        $this->assertSame(0, Stay::count());
    }

    public function test_a_room_already_given_for_those_nights_is_refused(): void
    {
        $this->room->update(['quantity' => 2]);
        Stay::factory()->confirmed()->create([
            'property_id' => $this->property->id,
            'room_type_id' => $this->room->id,
            'unit_id' => $this->unit->id,
            'check_in' => '2027-03-02',
            'check_out' => '2027-03-05',
        ]);

        $this->expectException(DeskRefusal::class);
        app(DirectBooking::class)->take($this->host, $this->room, CarbonImmutable::parse('2027-03-01'), CarbonImmutable::parse('2027-03-03'), ['name' => 'X', 'phone' => '1'], adults: 1, unit: $this->unit);
    }

    /** A free cell on the calendar opens the form with that room and night. */
    public function test_the_calendar_link_fills_the_form(): void
    {
        $this->actingAs($this->owner)
            ->get(NewBooking::getUrl(['unit' => $this->unit->id, 'check_in' => '2027-03-01'], panel: 'host', tenant: $this->host))
            ->assertOk();

        $this->inPanel();
        Livewire::withQueryParams(['unit' => $this->unit->id, 'check_in' => '2027-03-01'])
            ->actingAs($this->owner)
            ->test(NewBooking::class)
            ->assertSchemaStateSet(['room_type_id' => $this->room->id, 'unit_id' => $this->unit->id, 'check_in' => '2027-03-01', 'check_out' => '2027-03-02']);
    }

    // ── The calendar ─────────────────────────────────────────────────────

    public function test_the_calendar_shows_each_stay_in_its_room_and_the_loose_ones_on_their_own_row(): void
    {
        $this->room->update(['quantity' => 3]);
        $placed = Stay::factory()->confirmed()->create([
            'property_id' => $this->property->id, 'room_type_id' => $this->room->id, 'unit_id' => $this->unit->id,
            'check_in' => '2027-02-12', 'check_out' => '2027-02-15',
        ]);
        $loose = Stay::factory()->create([
            'property_id' => $this->property->id, 'room_type_id' => $this->room->id,
            'check_in' => '2027-02-20', 'check_out' => '2027-02-22', 'status' => Stay::REQUESTED,
        ]);
        $gone = Stay::factory()->create([
            'property_id' => $this->property->id, 'room_type_id' => $this->room->id,
            'check_in' => '2027-02-20', 'check_out' => '2027-02-22', 'status' => Stay::DECLINED,
        ]);

        $this->inPanel();
        $page = Livewire::actingAs($this->owner)->test(Calendar::class)->instance();
        $rows = collect($page->rows());

        $room4 = $rows->firstWhere('label', 'Room 4');
        $this->assertTrue($room4['cells']['2027-02-12'][0]->is($placed));
        $this->assertTrue($room4['cells']['2027-02-14'][0]->is($placed));
        $this->assertSame([], $room4['cells']['2027-02-15'], 'The morning they leave, the room is free.');

        $unassigned = $rows->firstWhere('label', 'Not yet in a room');
        $this->assertCount(1, $unassigned['cells']['2027-02-20'], 'A declined stay takes no night.');
        $this->assertTrue($unassigned['cells']['2027-02-20'][0]->is($loose));
        $this->assertFalse(collect($unassigned['cells'])->flatten()->contains(fn ($stay) => $stay->is($gone)));

        $this->assertStringContainsString('inset', Calendar::style($loose), 'A request is outlined, not filled.');
    }

    public function test_the_calendar_moves_by_month_and_shows_only_this_hosts_rooms(): void
    {
        $other = RoomType::factory()->create(['name' => ['en' => 'Somebody Elses Suite']]);
        PropertyUnit::factory()->create(['room_type_id' => $other->id, 'label' => 'Their Room']);

        $this->actingAs($this->owner)
            ->get(Calendar::getUrl(panel: 'host', tenant: $this->host))
            ->assertOk()
            ->assertSee('Room 4')
            ->assertDontSee('Their Room')
            ->assertSee('February 2027');

        $this->inPanel();
        Livewire::actingAs($this->owner)->test(Calendar::class)
            ->call('nextMonth')->assertSet('month', '2027-03')
            ->call('previousMonth')->call('previousMonth')->assertSet('month', '2027-01');
    }

    // ── Housekeeping ─────────────────────────────────────────────────────

    public function test_reception_marks_a_room_clean(): void
    {
        $this->unit->forceFill(['housekeeping' => PropertyUnit::DIRTY])->save();
        $reception = $this->member($this->host, HostRole::RECEPTION);

        $this->inPanel($reception);

        Livewire::actingAs($reception)
            ->test(Housekeeping::class)
            ->assertCanSeeTableRecords([$this->unit])
            ->callAction(TestAction::make('markClean')->table($this->unit));

        $this->assertSame(PropertyUnit::CLEAN, $this->unit->fresh()->housekeeping);
    }

    public function test_another_hosts_rooms_are_not_on_the_board(): void
    {
        $theirs = PropertyUnit::factory()->create();

        $this->inPanel();

        Livewire::actingAs($this->owner)
            ->test(Housekeeping::class)
            ->assertCanSeeTableRecords([$this->unit])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    // ── Today ────────────────────────────────────────────────────────────

    public function test_the_dashboard_counts_today(): void
    {
        $this->room->update(['quantity' => 5]);
        $make = fn (string $status, string $in, string $out): Stay => Stay::factory()->create([
            'property_id' => $this->property->id, 'room_type_id' => $this->room->id,
            'status' => $status, 'check_in' => $in, 'check_out' => $out, 'total_minor' => 20000,
        ]);

        $make(Stay::REQUESTED, '2027-03-01', '2027-03-03');
        $make(Stay::CONFIRMED, '2027-02-10', '2027-02-12');            // arriving
        $make(Stay::CHECKED_IN, '2027-02-08', '2027-02-10');           // leaving, owes USD 200
        $make(Stay::CONFIRMED, '2027-02-11', '2027-02-13');            // tomorrow: not today
        $paid = $make(Stay::CHECKED_IN, '2027-02-09', '2027-02-14');   // in house, paid up
        $paid->forceFill(['paid_minor' => 20000])->save();
        $this->unit->forceFill(['housekeeping' => PropertyUnit::DIRTY])->save();
        PropertyUnit::factory()->create(['room_type_id' => $this->room->id]); // clean

        $this->inPanel();

        $stats = collect((fn () => $this->getStats())->call(new Today))
            ->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => $stat->getValue()]);

        $this->assertSame(1, $stats['Waiting for your answer']);
        $this->assertSame(1, $stats['Arriving today']);
        $this->assertSame(1, $stats['Leaving today']);
        $this->assertSame(2, $stats['In house']);
        $this->assertSame(1, $stats['Still owing']);
        $this->assertSame(1, $stats['Rooms to clean']);

        $this->actingAs($this->owner)->get('/host/'.$this->host->slug)
            ->assertOk()
            ->assertSee('Arriving today')
            ->assertSee('Rooms to clean');
    }
}
