<?php

namespace Tests\Feature;

use App\Filament\Host\Pages\Earnings;
use App\Models\HostMembership;
use App\Models\HostStatement;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\Hosts\Statements;
use App\Services\Stays\Commission;
use App\Support\HostRole;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Monthly host statements — §16.9, §16 Phase 15.
 */
class HostStatementsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::factory()->commissionBased(15)->create(['name' => 'Coral Garden Inn']);
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

    private function marketplace(int $total, int $commission, string $currency = 'USD'): Stay
    {
        $stay = $this->stay(['source' => Commission::MARKETPLACE, 'total_minor' => $total, 'currency' => $currency]);
        $stay->forceFill(['commission_minor' => $commission, 'host_net_minor' => $total - $commission])->save();

        return $stay;
    }

    private function march(): CarbonImmutable
    {
        return CarbonImmutable::parse('2027-03-01');
    }

    private function owner(string $role = HostRole::OWNER, ?Partner $host = null): User
    {
        $user = User::factory()->create();
        HostMembership::create(['partner_id' => ($host ?? $this->host)->id, 'user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    public function test_a_month_is_issued_once_per_currency_with_its_figures(): void
    {
        $stay = $this->marketplace(20000, 3000);
        $payment = Payment::factory()->succeeded()->create(['payable_type' => Stay::class, 'payable_id' => $stay->id, 'currency' => 'USD', 'amount_minor' => 20000]);
        $payment->forceFill(['collected_by' => Payment::COLLECTED_BY_RIHLA])->save();
        $this->marketplace(10000, 1500, 'EUR');

        $issued = app(Statements::class)->issue($this->host, $this->march());

        $this->assertSame(['EUR', 'USD'], $issued->pluck('currency')->sort()->values()->all(), 'One per currency, never summed across them.');

        $usd = HostStatement::where('currency', 'USD')->sole();
        $this->assertSame('RIH-HS-202703-'.$this->host->id.'-USD', $usd->reference);
        $this->assertSame('2027-03-31', $usd->period_end->toDateString());
        $this->assertSame(20000, $usd->money('gross_minor')->minor);
        $this->assertSame(3000, $usd->money('commission_minor')->minor);
        $this->assertSame(17000, $usd->money('net_minor')->minor);
        $this->assertSame(17000, $usd->money('rihla_holds_minor')->minor);
        $this->assertSame(1500, HostStatement::where('currency', 'EUR')->sole()->money('commission_outstanding_minor')->minor);
    }

    /** The plan's plant: reissuing a month changes nothing already issued. */
    public function test_an_issued_statement_is_never_changed(): void
    {
        $this->marketplace(20000, 3000);
        app(Statements::class)->issue($this->host, $this->march());

        // A late correction to the month.
        $this->marketplace(50000, 7500);

        $again = app(Statements::class)->issue($this->host, $this->march());

        $this->assertCount(0, $again);
        $this->assertSame(1, HostStatement::count());
        $this->assertSame(20000, HostStatement::sole()->gross_minor);
    }

    public function test_the_command_issues_last_month_for_active_hosts_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-04-02 03:15'));
        $this->marketplace(20000, 3000);

        $paused = Partner::factory()->create(['status' => Partner::STATUS_SUSPENDED]);
        $theirs = Property::factory()->create(['partner_id' => $paused->id, 'currency' => 'USD']);
        Stay::factory()->create([
            'property_id' => $theirs->id, 'room_type_id' => RoomType::factory()->create(['property_id' => $theirs->id])->id,
            'status' => Stay::COMPLETED, 'check_in' => '2027-03-10', 'check_out' => '2027-03-12', 'currency' => 'USD', 'source' => 'phone', 'total_minor' => 10000,
        ]);

        $this->artisan('stays:statements')->expectsOutputToContain('Issued 1 statement(s) for March 2027.')->assertSuccessful();
        $this->artisan('stays:statements')->expectsOutputToContain('Issued 0 statement(s)')->assertSuccessful();
        $this->artisan('stays:statements', ['--month' => '2027-02'])->expectsOutputToContain('February 2027')->assertSuccessful();

        $this->assertSame([$this->host->id], HostStatement::pluck('partner_id')->all());
    }

    public function test_the_pdf_carries_the_figures_and_the_reference(): void
    {
        $this->marketplace(20000, 3000);
        $statement = app(Statements::class)->issue($this->host, $this->march())->sole();

        $pdf = app(Statements::class)->pdf($statement);

        $this->assertStringStartsWith('%PDF', $pdf);
        $html = view('pdf.host-statement', [
            'statement' => $statement,
            'issuer' => ['name' => 'Rihla Travels', 'address' => null, 'registration' => null, 'phone' => '+960 000 0000', 'email' => null],
            'host' => $this->host,
        ])->render();
        $this->assertStringContainsString($statement->reference, $html);
        $this->assertStringContainsString('Coral Garden Inn', $html);
        $this->assertStringContainsString($statement->money('net_minor')->format(), $html);
        $this->assertStringContainsString('USD 170', $statement->money('net_minor')->format());
    }

    // ── Who sees which ───────────────────────────────────────────────────

    private function inPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);
    }

    public function test_the_host_downloads_their_own_statement(): void
    {
        $this->marketplace(20000, 3000);
        $statement = app(Statements::class)->issue($this->host, $this->march())->sole();
        $owner = $this->owner();
        $this->inPanel($owner);

        Livewire::actingAs($owner)->test(Earnings::class)
            ->assertSee($statement->reference)
            ->call('downloadStatement', $statement->id)
            ->assertFileDownloaded($statement->reference.'.pdf');
    }

    /** The plan's plant: another host's statement is not downloadable. */
    public function test_another_hosts_statement_is_out_of_reach(): void
    {
        $other = Partner::factory()->commissionBased(15)->create();
        $theirs = Property::factory()->create(['partner_id' => $other->id, 'currency' => 'USD']);
        $stay = Stay::factory()->create([
            'property_id' => $theirs->id, 'room_type_id' => RoomType::factory()->create(['property_id' => $theirs->id])->id,
            'status' => Stay::COMPLETED, 'check_in' => '2027-03-10', 'check_out' => '2027-03-12', 'currency' => 'USD', 'source' => 'phone', 'total_minor' => 10000,
        ]);
        $statement = app(Statements::class)->issue($other, $this->march())->sole();
        $this->assertNotNull($stay);

        $owner = $this->owner();
        $this->inPanel($owner);

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($owner)->test(Earnings::class)
            ->assertDontSee($statement->reference)
            ->call('downloadStatement', $statement->id);
    }

    public function test_reception_never_reaches_a_statement(): void
    {
        $this->actingAs($this->owner(HostRole::RECEPTION))->get('/host/'.$this->host->slug.'/earnings')->assertForbidden();
    }
}
