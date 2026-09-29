<?php

namespace Tests\Feature;

use App\Exceptions\PayoutRefused;
use App\Filament\Host\Pages\Earnings;
use App\Filament\Host\Pages\PayoutDetails;
use App\Filament\Resources\HostStatements\Pages\ListHostStatements;
use App\Filament\Resources\Partners\Pages\ListPartners;
use App\Models\HostMembership;
use App\Models\HostStatement;
use App\Models\Partner;
use App\Models\Payout;
use App\Models\User;
use App\Services\Hosts\Payouts;
use App\Support\Access;
use App\Support\HostRole;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Paying hosts — §16.9, §16 Phase 16.
 */
class HostPayoutsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private HostStatement $statement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::factory()->commissionBased(15)->create(['name' => 'Coral Garden Inn']);
        $this->host->forceFill([
            'settlement_model' => Partner::FULL_COLLECTION,
            'payout_bank_name' => 'Bank of Maldives',
            'payout_account_name' => 'Coral Garden Pvt Ltd',
            'payout_account_number' => '7730000099887',
        ])->save();

        $this->statement = $this->statement($this->host, 'USD', 17000);
    }

    private function statement(Partner $host, string $currency, int $holds): HostStatement
    {
        $statement = new HostStatement;
        $statement->forceFill([
            'partner_id' => $host->id, 'period_start' => '2027-03-01', 'period_end' => '2027-03-31', 'currency' => $currency,
            'marketplace_count' => 1, 'gross_minor' => 20000, 'commission_minor' => 3000, 'net_minor' => 17000,
            'direct_count' => 0, 'direct_gross_minor' => 0, 'paid_to_rihla_minor' => 20000, 'paid_here_minor' => 0,
            'rihla_holds_minor' => $holds, 'commission_outstanding_minor' => 0,
            'reference' => 'RIH-HS-202703-'.$host->id.'-'.$currency, 'issued_at' => now(),
        ])->save();

        return $statement;
    }

    private function pay(int $minor, string $currency = 'USD', string $reference = 'BML-TRF-0001'): Payout
    {
        return app(Payouts::class)->record($this->statement, Money::ofMinor($minor, $currency), CarbonImmutable::parse('2027-04-05'), $reference);
    }

    // ── The ledger ───────────────────────────────────────────────────────

    public function test_a_payout_reduces_what_is_still_owed_and_never_goes_past_it(): void
    {
        $this->pay(10000);
        $this->assertSame(7000, $this->statement->fresh()->stillOwed()->minor);

        try {
            $this->pay(7001);
            $this->fail('Paid more than was owed.');
        } catch (PayoutRefused $refusal) {
            $this->assertStringContainsString('USD 70', $refusal->getMessage());
        }

        // The same host's other statement is untouched by this one's payouts.
        $april = $this->statement($this->host, 'EUR', 5000);
        $this->assertSame(5000, $april->stillOwed()->minor);

        $this->pay(7000);
        $this->assertSame(0, $this->statement->fresh()->stillOwed()->minor);
        $this->assertSame(5000, $april->fresh()->stillOwed()->minor);
        $this->assertSame(17000, $this->statement->fresh()->paidOut()->minor);
    }

    public function test_a_payout_is_refused_in_another_currency_or_to_nowhere(): void
    {
        try {
            $this->pay(1000, 'MVR');
            $this->fail('A USD statement paid in MVR.');
        } catch (PayoutRefused) {
        }

        $this->host->forceFill(['payout_account_number' => null])->save();

        $this->expectException(PayoutRefused::class);
        $this->pay(1000);
    }

    public function test_the_account_number_is_ciphertext_at_rest(): void
    {
        $raw = (string) DB::table('partners')->where('id', $this->host->id)->value('payout_account_number');

        $this->assertStringNotContainsString('7730000099887', $raw);
        $this->assertSame('7730000099887', $this->host->fresh()->payout_account_number);
        $this->assertSame('•••••••••9887', $this->host->fresh()->maskedPayoutAccount());
    }

    // ── Finance records it ───────────────────────────────────────────────

    public function test_finance_records_a_payout_against_a_statement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-04-06'));
        $finance = User::factory()->create()->assignRole(Access::FINANCE);
        $this->actingAs($finance);
        Filament::setCurrentPanel(Filament::getPanel('staff'));

        // Money that has not left the bank yet is not recorded as sent.
        Livewire::test(ListHostStatements::class)
            ->callAction(TestAction::make('recordPayout')->table($this->statement), data: [
                'amount' => 1, 'paid_on' => '2027-04-09', 'reference' => 'BML-TRF-FUTURE',
            ])
            ->assertHasFormErrors(['paid_on']);

        Livewire::test(ListHostStatements::class)
            ->assertTableActionVisible('recordPayout', $this->statement)
            ->callAction(TestAction::make('recordPayout')->table($this->statement), data: [
                'amount' => 170, 'paid_on' => '2027-04-05', 'reference' => 'BML-TRF-7788',
            ])
            ->assertHasNoFormErrors();

        $payout = Payout::sole();
        $this->assertSame(17000, $payout->amount_minor);
        $this->assertSame('BML-TRF-7788', $payout->reference);
        $this->assertSame($finance->id, $payout->recorded_by);
    }

    public function test_only_finance_records_payouts(): void
    {
        $booking = User::factory()->create()->assignRole(Access::BOOKING_STAFF);

        $this->assertFalse($booking->can('payout.create'));
        $this->assertTrue(User::factory()->create()->assignRole(Access::FINANCE)->can('payout.create'));
        $this->assertFalse(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER)->can('payout.create'));
    }

    // ── The host's side ──────────────────────────────────────────────────

    private function member(string $role): User
    {
        $user = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    private function inPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);
    }

    public function test_the_owner_sets_where_the_money_goes_and_a_manager_cannot(): void
    {
        $this->actingAs($this->member(HostRole::MANAGER))->get('/host/'.$this->host->slug.'/payout-details')->assertForbidden();

        $owner = $this->member(HostRole::OWNER);
        $this->inPanel($owner);

        Livewire::actingAs($owner)->test(PayoutDetails::class)
            ->fillForm(['payout_bank_name' => 'State Bank of India', 'payout_account_name' => 'Coral Garden', 'payout_account_number' => '1234 5678 90'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1234567890', $this->host->fresh()->payout_account_number);
        $this->assertSame('State Bank of India', $this->host->fresh()->payout_bank_name);
    }

    public function test_the_host_sees_what_was_paid_to_them(): void
    {
        $this->pay(10000);
        $owner = $this->member(HostRole::OWNER);
        $this->inPanel($owner);

        Livewire::actingAs($owner)->test(Earnings::class)
            ->assertSee('Paid to you USD 100')
            ->assertSee('Still owed USD 70');
    }

    // ── Settlement ───────────────────────────────────────────────────────

    public function test_staff_switch_a_host_to_full_collection(): void
    {
        $other = Partner::factory()->create();
        $this->assertSame(Partner::COMMISSION_DEPOSIT, $other->settlement_model);

        $this->actingAs(User::factory()->create()->assignRole(Access::SUPER_ADMIN));
        Filament::setCurrentPanel(Filament::getPanel('staff'));

        Livewire::test(ListPartners::class)
            ->callAction(TestAction::make('settlementModel')->table($other), data: ['settlement_model' => Partner::FULL_COLLECTION]);

        $this->assertSame(Partner::FULL_COLLECTION, $other->fresh()->settlement_model);
    }
}
