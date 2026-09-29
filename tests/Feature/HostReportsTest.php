<?php

namespace Tests\Feature;

use App\Filament\Host\Resources\Bookings\Pages\ListBookings;
use App\Models\AuditLog;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayGuest;
use App\Models\User;
use App\Services\Hosts\Reports;
use App\Services\Stays\Commission;
use App\Support\HostRole;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A host's reports — §16.10, §16 Phase 15.
 */
class HostReportsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Property $property;

    private RoomType $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::factory()->commissionBased(15)->create();
        $this->property = Property::factory()->create(['partner_id' => $this->host->id, 'currency' => 'USD', 'name' => ['en' => 'Garden House']]);
        $this->room = RoomType::factory()->create(['property_id' => $this->property->id, 'quantity' => 2]);
        PropertyUnit::factory()->count(2)->create(['room_type_id' => $this->room->id]);
    }

    private function stay(array $attributes): Stay
    {
        return Stay::factory()->create($attributes + [
            'property_id' => $this->property->id,
            'room_type_id' => $this->room->id,
            'status' => Stay::COMPLETED,
            'currency' => 'USD',
            'audience' => 'tourist',
        ]);
    }

    private function march(): array
    {
        return app(Reports::class)->forRange($this->host, CarbonImmutable::parse('2027-03-01'), CarbonImmutable::parse('2027-03-31'));
    }

    public function test_a_stay_crossing_the_edge_counts_only_its_nights_inside(): void
    {
        // 28 Feb → 3 Mar is three nights, two of them in March.
        $crossing = $this->stay(['check_in' => '2027-02-28', 'check_out' => '2027-03-03', 'nights' => 3, 'total_minor' => 30000, 'source' => 'phone']);
        $this->stay(['check_in' => '2027-03-10', 'check_out' => '2027-03-12', 'nights' => 2, 'total_minor' => 20000, 'source' => Commission::MARKETPLACE, 'commission_minor' => 3000]);
        $this->stay(['check_in' => '2027-03-20', 'check_out' => '2027-03-22', 'nights' => 2, 'total_minor' => 99900, 'status' => Stay::CANCELLED]);

        $report = $this->march();

        $this->assertSame(2, Reports::nightsWithin($crossing, CarbonImmutable::parse('2027-03-01'), CarbonImmutable::parse('2027-04-01')));
        $this->assertSame(31, $report['nights']);

        $this->assertSame(4, $report['occupancy'][0]['occupied'], 'Two nights of each stay; the cancelled one takes none.');
        $this->assertSame(62, $report['occupancy'][0]['available'], 'Two rooms × 31 nights.');
        $this->assertSame(6.5, $report['occupancy'][0]['rate']);

        $usd = $report['money']['USD'];
        $this->assertSame(40000, $usd['revenue']->minor, 'Two thirds of 300 and all of 200.');
        $this->assertSame(10000, $usd['average_rate']->minor);
        $this->assertSame(3000, $usd['commission']->minor);
        $this->assertSame(20000, $usd['by_source']['Rihla marketplace']->minor);
        $this->assertSame(20000, $usd['by_source']['Phone']->minor);
    }

    /** No tax row from a rate nobody has stated. */
    public function test_the_tax_rows_wait_for_a_stated_rate(): void
    {
        $this->stay(['check_in' => '2027-03-10', 'check_out' => '2027-03-11', 'nights' => 1, 'total_minor' => 11200, 'source' => 'phone']);

        $this->assertNull($this->march()['money']['USD']['tgst']);

        config(['stays.tax.tgst_pct' => 12]);

        // 112 including 12% → 12 of it is tax.
        $this->assertSame(1200, $this->march()['money']['USD']['tgst']->minor);
    }

    public function test_green_tax_comes_from_each_stays_own_snapshot(): void
    {
        $this->stay([
            'check_in' => '2027-03-30', 'check_out' => '2027-04-02', 'nights' => 3, 'total_minor' => 30000, 'source' => 'phone',
            'rate_snapshot' => ['green_tax' => ['applies' => true, 'guests' => 2, 'per_guest_per_night_minor' => 600, 'currency' => 'USD']],
        ]);
        $this->stay([
            'check_in' => '2027-03-05', 'check_out' => '2027-03-07', 'nights' => 2, 'total_minor' => 90000, 'currency' => 'MVR', 'audience' => 'local', 'source' => 'phone',
            'rate_snapshot' => ['green_tax' => ['applies' => false]],
        ]);

        // Two guests × two March nights × USD 6.
        $this->assertSame(2400, $this->march()['green_tax']['USD']->minor);
        $this->assertArrayNotHasKey('MVR', $this->march()['green_tax']);
    }

    // ── Who sees what ────────────────────────────────────────────────────

    private function member(string $role): User
    {
        $user = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    public function test_reports_are_for_owner_and_manager(): void
    {
        $this->actingAs($this->member(HostRole::RECEPTION))->get('/host/'.$this->host->slug.'/reports')->assertForbidden();
        $this->actingAs($this->member(HostRole::MANAGER))->get('/host/'.$this->host->slug.'/reports')->assertOk()->assertSee('Occupancy');
    }

    public function test_the_register_export_masks_identifiers_for_reception_and_is_logged(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-03-15'));
        $stay = $this->stay(['check_in' => '2027-03-10', 'check_out' => '2027-03-12', 'nights' => 2, 'total_minor' => 20000]);
        StayGuest::create(['stay_id' => $stay->id, 'full_name' => 'Aminath Guest', 'id_number' => 'C01X00T47', 'is_lead' => true]);

        foreach ([HostRole::RECEPTION => false, HostRole::OWNER => true] as $role => $whole) {
            $user = $this->member($role);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('host'));
            Filament::getPanel('host')->boot();
            Filament::setTenant($this->host);

            $response = Livewire::actingAs($user)->test(ListBookings::class)
                ->callAction('register', data: ['from' => '2027-03-01', 'until' => '2027-03-31'])
                ->assertFileDownloaded('guest-register-2027-03-01.csv');

            $csv = base64_decode((string) $response->effects['download']['content']);
            $this->assertStringContainsString('Aminath Guest', $csv);
            $whole
                ? $this->assertStringContainsString('C01X00T47', $csv)
                : $this->assertStringNotContainsString('C01X00T47', $csv);
        }

        $this->assertSame(2, AuditLog::where('event', AuditLog::DOWNLOADED)->where('auditable_type', $this->host->getMorphClass())->count());
    }
}
