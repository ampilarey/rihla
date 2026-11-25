<?php

namespace Modules\Transport\Tests;

use App\Exceptions\DeskRefusal;
use App\Models\Island;
use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Transport\Filament\Pages\BoatScan;
use Modules\Transport\Filament\Resources\BoatBookings\Pages\ManageBoatBookings;
use Modules\Transport\Filament\Resources\OperatorStatements\Pages\ManageOperatorStatements;
use Modules\Transport\Filament\Resources\Sailings\Pages\ManageSailings;
use Modules\Transport\Filament\Resources\Vessels\Pages\ManageVessels;
use Modules\Transport\Http\Controllers\BoatScanRedirect;
use Modules\Transport\Models\BoatRoute;
use Modules\Transport\Models\OperatorMembership;
use Modules\Transport\Models\OperatorStatement;
use Modules\Transport\Models\Sailing;
use Modules\Transport\Models\Vessel;
use Modules\Transport\Services\OperatorStatements;
use Modules\Transport\Services\OperatorTeam;
use Modules\Transport\Services\TransportDesk;
use Tests\TestCase;

/**
 * Transport T5 (docs/TRANSPORT_PLAN.md): an outside operator runs their
 * timetable from their own panel, sees nobody else's, and is paid what
 * their online seats earned less Rihla's commission.
 */
class OperatorPanelTest extends TestCase
{
    use RefreshDatabase;

    private Partner $coral;

    private Partner $other;

    private User $owner;

    private BoatRoute $route;

    private Vessel $boat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 06:00'));
        Notification::fake();

        $this->coral = Partner::factory()->create(['name' => 'Coral Ferries', 'kind' => Partner::KIND_OPERATOR, 'commission_pct' => 10]);
        $this->other = Partner::factory()->create(['name' => 'Other Boats', 'kind' => Partner::KIND_OPERATOR, 'commission_pct' => 10]);
        [$from, $to] = Island::query()->orderBy('id')->limit(2)->get()->all();

        $this->boat = Vessel::create(['partner_id' => $this->coral->id, 'name' => 'Coral One', 'kind' => Vessel::LAUNCH, 'seats' => 20]);
        $this->route = BoatRoute::create(['partner_id' => $this->coral->id, 'origin_island_id' => $from->id, 'destination_island_id' => $to->id, 'duration_minutes' => 60, 'adult_fare_minor' => 20000]);

        $this->owner = app(OperatorTeam::class)->add($this->coral, 'Hassan', 'hassan@coral.example', OperatorMembership::OWNER, null)->user()->firstOrFail();
    }

    private function enter(Partner $operator): void
    {
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        Filament::setTenant($operator);
    }

    private function theirs(): Sailing
    {
        return Sailing::create(['boat_route_id' => $this->route->id, 'vessel_id' => $this->boat->id, 'departs_at' => now()->addDay(), 'seats_total' => 20]);
    }

    private function others(): Sailing
    {
        [$from, $to] = Island::query()->orderBy('id')->limit(2)->get()->all();
        $boat = Vessel::create(['partner_id' => $this->other->id, 'name' => 'Not theirs', 'kind' => Vessel::LAUNCH, 'seats' => 20]);
        $route = BoatRoute::create(['partner_id' => $this->other->id, 'origin_island_id' => $to->id, 'destination_island_id' => $from->id, 'duration_minutes' => 60, 'adult_fare_minor' => 20000]);

        return Sailing::create(['boat_route_id' => $route->id, 'vessel_id' => $boat->id, 'departs_at' => now()->addDay(), 'seats_total' => 20]);
    }

    public function test_a_new_owner_is_sent_a_password_link_and_lands_in_their_own_panel_only(): void
    {
        Notification::assertSentTo($this->owner, ResetPassword::class);

        $this->actingAs($this->owner)->get('/operator/'.$this->coral->slug.'/sailings')->assertOk();
        $this->actingAs($this->owner)->get('/operator/'.$this->other->slug.'/sailings')->assertNotFound();
        $this->actingAs($this->owner)->get('/staff/sailings')->assertForbidden();
        $this->actingAs($this->owner)->get('/host')->assertForbidden();
    }

    public function test_the_owner_runs_their_timetable_and_sees_nobody_elses(): void
    {
        $mine = $this->theirs();
        $notMine = $this->others();
        $this->actingAs($this->owner);
        $this->enter($this->coral);

        Livewire::test(ManageSailings::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$notMine])
            ->callAction('timetable', data: [
                'boat_route_id' => $this->route->id, 'vessel_id' => $this->boat->id,
                'from' => '2026-11-16', 'to' => '2026-11-22', 'weekdays' => [1, 3, 5], 'times' => ['08:00'],
            ])
            ->assertNotified('3 sailings added');

        // A boat added in their panel is theirs, whatever the form sent.
        Livewire::test(ManageVessels::class)
            ->callAction('create', data: ['name' => 'Coral Two', 'kind' => Vessel::SPEEDBOAT, 'seats' => 8, 'is_active' => true]);
        $this->assertSame($this->coral->id, Vessel::query()->where('name', 'Coral Two')->value('partner_id'));

        // Another operator's route cannot be scheduled from here.
        $this->expectException(DeskRefusal::class);
        Sailing::create(['boat_route_id' => $notMine->boat_route_id, 'vessel_id' => $notMine->vessel_id, 'departs_at' => now()->addDays(3), 'seats_total' => 5]);
    }

    public function test_crew_board_their_own_boats_only_and_hold_no_rihla_powers(): void
    {
        $crew = app(OperatorTeam::class)->add($this->coral, 'Ali', 'ali@coral.example', OperatorMembership::CREW, $this->owner)->user()->firstOrFail();
        $crew->assignRole(Access::OPERATIONS_MANAGER); // even a Rihla role does not speak in here

        $desk = app(TransportDesk::class);
        $mine = $desk->book($this->theirs(), [['full_name' => 'Aminath']], 'A', '1');
        $desk->confirm($mine);
        $notMine = $desk->book($this->others(), [['full_name' => 'Stranger']], 'S', '2');
        $desk->confirm($notMine);

        $this->actingAs($crew);
        $this->enter($this->coral);
        $this->assertFalse($crew->can('transport.manage'), 'Crew do not run the timetable.');
        $this->assertFalse($crew->can('booking.viewAny'), 'Nothing outside transport is granted in the operator panel.');

        Livewire::withQueryParams(['t' => $notMine->tickets()->firstOrFail()->token])->test(BoatScan::class)
            ->assertNotified('No ticket matches that');

        Livewire::withQueryParams(['t' => $mine->tickets()->firstOrFail()->token])->test(BoatScan::class)
            ->call('board')->assertNotified('Aminath is on board');

        Livewire::test(ManageBoatBookings::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$notMine]);
    }

    public function test_the_qr_sends_staff_and_an_operators_crew_to_their_own_boarding_page(): void
    {
        $booking = app(TransportDesk::class)->book($this->theirs(), [['full_name' => 'Aminath']], 'A', '1');
        $token = $booking->tickets()->firstOrFail()->token;

        $this->get(BoatScanRedirect::urlFor($token))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER))
            ->get(BoatScanRedirect::urlFor($token))->assertRedirect(url('/staff/boat-scan').'?t='.$token);
        $this->actingAs($this->owner)
            ->get(BoatScanRedirect::urlFor($token))->assertRedirect(url('/operator/'.$this->coral->slug.'/boat-scan').'?t='.$token);

        $stranger = app(OperatorTeam::class)->add($this->other, '', 'x@other.example', OperatorMembership::OWNER, null)->user()->firstOrFail();
        $this->actingAs($stranger)->get(BoatScanRedirect::urlFor($token))->assertForbidden();
    }

    public function test_online_seats_carry_the_commission_and_the_months_statement_says_what_is_owed(): void
    {
        $desk = app(TransportDesk::class);
        $sailing = $this->theirs();

        $online = $desk->book($sailing, [['full_name' => 'A'], ['full_name' => 'B']], 'Online', '1');
        $this->assertSame(10, $online->commission_pct, 'Frozen from the operator when booked online.');
        $online->storePaidTotal(40000);

        $atDesk = $desk->book($sailing, [['full_name' => 'C']], 'Desk', '2', null, $this->owner);
        $this->assertNull($atDesk->commission_pct, 'A seat the operator sells carries none.');
        $desk->confirm($atDesk);

        $this->coral->forceFill(['commission_pct' => 25])->save(); // a later change does not reach old seats

        try {
            app(OperatorStatements::class)->issue($this->coral, CarbonImmutable::parse('2026-11-01'), null);
            $this->fail('A month was issued before it was over.');
        } catch (DeskRefusal $refusal) {
            $this->assertSame('November 2026 is not over yet.', $refusal->getMessage());
        }

        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00'));
        $staff = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);
        Livewire::actingAs($staff)->test(ManageOperatorStatements::class)
            ->callAction('issue', data: ['month' => '2026-11'])
            ->assertNotified('1 statement(s) issued');

        $statement = OperatorStatement::query()->sole();
        $this->assertSame($this->coral->id, $statement->partner_id);
        $this->assertSame(1, $statement->online_count);
        $this->assertSame(40000, $statement->online_paid_minor);
        $this->assertSame(4000, $statement->commission_minor);
        $this->assertSame(36000, $statement->payable_minor);
        $this->assertSame(1, $statement->desk_count);
        $this->assertSame(20000, $statement->desk_total_minor);

        $this->expectExceptionMessage('already been issued');
        app(OperatorStatements::class)->issue($this->coral, CarbonImmutable::parse('2026-11-01'), null);
    }

    public function test_the_operator_reads_only_their_own_statements_and_can_download_one(): void
    {
        $desk = app(TransportDesk::class);
        $theirs = $desk->book($this->theirs(), [['full_name' => 'A']], 'X', '1');
        $theirs->storePaidTotal(20000);
        $others = $desk->book($this->others(), [['full_name' => 'B']], 'Y', '2');
        $others->storePaidTotal(20000);

        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00'));
        [$mine] = app(OperatorStatements::class)->issue($this->coral, CarbonImmutable::parse('2026-11-01'), null);
        [$notMine] = app(OperatorStatements::class)->issue($this->other, CarbonImmutable::parse('2026-11-01'), null);

        $this->actingAs($this->owner);
        $this->enter($this->coral);
        Livewire::test(ManageOperatorStatements::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$notMine])
            ->assertActionHidden('issue')
            ->callTableAction('pdf', $mine)
            ->assertFileDownloaded($mine->reference.'.pdf');
    }

    public function test_rihla_is_never_given_a_statement_or_an_operator_panel(): void
    {
        $rihla = Partner::rihla();
        $this->assertNotNull($rihla);

        $this->expectException(DeskRefusal::class);
        app(OperatorTeam::class)->add($rihla, 'X', 'x@rihla.example', OperatorMembership::OWNER, null);
    }
}
