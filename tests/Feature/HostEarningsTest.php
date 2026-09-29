<?php

namespace Tests\Feature;

use App\Filament\Host\Pages\Earnings;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\Hosts\Earnings as EarningsReport;
use App\Services\Stays\Commission;
use App\Support\HostRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a host earned — §16.6, §16.9, §16 Phase 14.5.
 */
class HostEarningsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::factory()->commissionBased(15)->create();
        $this->property = Property::factory()->create(['partner_id' => $this->host->id, 'currency' => 'USD']);
    }

    private function stay(array $attributes): Stay
    {
        return Stay::factory()->create($attributes + [
            'property_id' => $this->property->id,
            'room_type_id' => RoomType::factory()->create(['property_id' => $this->property->id])->id,
            'status' => Stay::COMPLETED,
            'check_in' => '2027-03-10',
            'check_out' => '2027-03-12',
            'currency' => 'USD',
        ]);
    }

    private function paid(Stay $stay, int $minor, string $collector): void
    {
        $payment = Payment::factory()->succeeded()->create([
            'payable_type' => Stay::class, 'payable_id' => $stay->id, 'currency' => $stay->currency, 'amount_minor' => $minor,
        ]);
        $payment->forceFill(['collected_by' => $collector])->save();
    }

    public function test_the_month_splits_marketplace_from_direct_and_says_who_holds_what(): void
    {
        $marketplace = $this->stay(['source' => Commission::MARKETPLACE, 'total_minor' => 20000]);
        $marketplace->forceFill(['commission_minor' => 3000, 'host_net_minor' => 17000])->save();
        $this->paid($marketplace, 3000, Payment::COLLECTED_BY_RIHLA);
        $this->paid($marketplace, 17000, Payment::COLLECTED_BY_HOST);

        $this->stay(['source' => 'walk_in', 'total_minor' => 10000]);
        $this->stay(['source' => 'phone', 'total_minor' => 500000, 'currency' => 'MVR']);

        // Not this month; not this host.
        $this->stay(['source' => 'phone', 'total_minor' => 99900, 'check_in' => '2027-04-01', 'check_out' => '2027-04-03']);
        Stay::factory()->create(['status' => Stay::COMPLETED, 'check_out' => '2027-03-12', 'total_minor' => 77700]);

        $report = app(EarningsReport::class)->forMonth($this->host, CarbonImmutable::parse('2027-03-01'));

        $this->assertSame(['MVR', 'USD'], array_keys($report), 'Never summed across two currencies.');

        $usd = $report['USD'];
        $this->assertSame(1, $usd['marketplace_count']);
        $this->assertSame(20000, $usd['marketplace_gross']->minor);
        $this->assertSame(3000, $usd['commission']->minor);
        $this->assertSame(17000, $usd['host_net']->minor);
        $this->assertSame(1, $usd['direct_count']);
        $this->assertSame(10000, $usd['direct_gross']->minor);
        $this->assertSame(17000, $usd['paid_here']->minor);
        $this->assertSame(3000, $usd['paid_to_rihla']->minor);
        $this->assertSame(0, $usd['rihla_holds_for_host']->minor);
        $this->assertSame(0, $usd['commission_outstanding']->minor);

        $this->assertSame(500000, $report['MVR']['direct_gross']->minor);
    }

    public function test_money_rihla_holds_beyond_its_commission_is_the_hosts(): void
    {
        $stay = $this->stay(['source' => Commission::MARKETPLACE, 'total_minor' => 20000]);
        $stay->forceFill(['commission_minor' => 3000, 'host_net_minor' => 17000])->save();
        $this->paid($stay, 20000, Payment::COLLECTED_BY_RIHLA);

        $unpaid = $this->stay(['source' => Commission::MARKETPLACE, 'total_minor' => 10000, 'currency' => 'EUR']);
        $unpaid->forceFill(['commission_minor' => 1500, 'host_net_minor' => 8500])->save();

        $report = app(EarningsReport::class)->forMonth($this->host, CarbonImmutable::parse('2027-03-01'));

        $this->assertSame(17000, $report['USD']['rihla_holds_for_host']->minor);
        $this->assertSame(1500, $report['EUR']['commission_outstanding']->minor);
    }

    /** The plan's plant: a reception member opening earnings → 403. */
    public function test_reception_does_not_see_the_money(): void
    {
        $reception = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $reception->id, 'role' => HostRole::RECEPTION, 'accepted_at' => now()]);
        $manager = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $manager->id, 'role' => HostRole::MANAGER, 'accepted_at' => now()]);

        $this->actingAs($reception)->get('/host/'.$this->host->slug.'/earnings')->assertForbidden();
        $this->actingAs($manager)->get('/host/'.$this->host->slug.'/earnings')->assertOk()->assertSee('Stays that ended in');
    }

    public function test_the_page_moves_by_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-03-15'));
        $this->stay(['source' => 'walk_in', 'total_minor' => 10000]);

        $owner = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $owner->id, 'role' => HostRole::OWNER, 'accepted_at' => now()]);

        $this->actingAs($owner)->get('/host/'.$this->host->slug.'/earnings')
            ->assertSee('March 2027')
            ->assertSee('USD 100');
        $this->actingAs($owner)->get('/host/'.$this->host->slug.'/earnings?month=2027-02')
            ->assertSee('February 2027')
            ->assertSee('Nothing this month');
    }
}
