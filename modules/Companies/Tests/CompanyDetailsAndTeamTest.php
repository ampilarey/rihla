<?php

namespace Modules\Companies\Tests;

use App\Models\Partner;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Drivers\BankTransfer;
use App\Support\Access;
use App\Support\CompanyContext;
use App\Support\CompanyRole;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Companies\Filament\Pages\CompanySettings;
use Modules\Companies\Filament\Pages\Team;
use Modules\Companies\Models\CompanyMembership;
use Modules\Companies\Services\AddCompany;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Services\Invoices\Documents;
use Tests\TestCase;

/**
 * Phase 2c of docs/UMRAH_COMPANIES_PLAN.md: a company's own details on its
 * documents, payment instructions and references, and the Owner's team.
 */
class CompanyDetailsAndTeamTest extends TestCase
{
    use RefreshDatabase;

    private Partner $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->company = app(AddCompany::class)('Coral Umrah', 'Aishath Coral', 'owner@coral.example');
        $this->owner = User::where('email', 'owner@coral.example')->sole();
    }

    private function inPanel(?User $as = null): void
    {
        $this->actingAs($as ?? $this->owner);
        Filament::setCurrentPanel('company');
        Filament::setTenant($this->company);
        app(CompanyContext::class)->use($this->company->id);
    }

    private function details(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Coral Umrah Pvt Ltd',
            'registration_number' => 'C-0420/2019',
            'address' => 'Majeedhee Magu, Malé',
            'phone' => '+960 333 4444',
            'email' => 'office@coral.example',
            'reference_prefix' => 'CRL',
            'bank_name' => 'Bank of Maldives',
            'bank_account_name' => 'Coral Umrah Pvt Ltd',
            'bank_accounts' => ['MVR' => '7701-123456-001', 'USD' => '', 'SAR' => ''],
            'invoice_terms' => 'Deposits are refundable until 60 days before departure.',
        ];
    }

    public function test_the_owner_fills_in_the_company_details(): void
    {
        $this->inPanel();

        Livewire::test(CompanySettings::class)
            ->fillForm($this->details())
            ->call('save')
            ->assertHasNoFormErrors();

        $company = $this->company->fresh();
        $this->assertSame('Coral Umrah Pvt Ltd', $company->name);
        $this->assertSame('CRL', $company->reference_prefix);
        $this->assertSame(['MVR' => '7701-123456-001'], $company->bank_accounts, 'Blank accounts are not stored as accounts.');
    }

    public function test_letters_must_be_the_companys_own(): void
    {
        $this->inPanel();

        Livewire::test(CompanySettings::class)
            ->fillForm($this->details(['reference_prefix' => 'RIH']))
            ->call('save')
            ->assertHasFormErrors(['reference_prefix']);

        Partner::factory()->create()->forceFill(['reference_prefix' => 'CRL'])->save();

        Livewire::test(CompanySettings::class)
            ->fillForm($this->details())
            ->call('save')
            ->assertHasFormErrors(['reference_prefix' => 'unique']);
    }

    public function test_only_the_owner_opens_the_details_and_the_team(): void
    {
        $finance = User::factory()->create();
        CompanyMembership::create(['company_id' => $this->company->id, 'user_id' => $finance->id, 'role' => Access::FINANCE, 'accepted_at' => now()]);

        $this->actingAs($this->owner)->get(CompanySettings::getUrl(panel: 'company', tenant: $this->company))->assertOk();
        $this->actingAs($this->owner)->get(Team::getUrl(panel: 'company', tenant: $this->company))->assertOk();

        // A fresh session per person: the panel's AuthenticateSession signs
        // out a session whose user changes under it.
        $this->flushSession();
        $this->actingAs($finance)->get(CompanySettings::getUrl(panel: 'company', tenant: $this->company))->assertForbidden();
        $this->actingAs($finance)->get(Team::getUrl(panel: 'company', tenant: $this->company))->assertForbidden();
    }

    public function test_the_companys_documents_and_bank_instructions_are_its_own_never_rihlas(): void
    {
        config([
            'payments.bank.name' => "Rihla's bank",
            'payments.bank.account_name' => 'Rihla Travels',
            'payments.bank.accounts' => ['MVR' => 'RIHLA-0001', 'USD' => 'RIHLA-0002'],
        ]);
        $this->company->forceFill($this->details(['bank_accounts' => ['MVR' => '7701-123456-001']]))->save();

        $rihla = app(Documents::class)->issuer();
        $this->assertSame((string) config('invoices.issuer.name'), $rihla['name']);

        $this->inPanel();
        $issuer = app(Documents::class)->issuer();
        $this->assertSame('Coral Umrah Pvt Ltd', $issuer['name']);
        $this->assertSame('C-0420/2019', $issuer['registration']);
        $this->assertSame('+960 333 4444', $issuer['phone']);

        $bank = app(BankTransfer::class);
        $this->assertSame('7701-123456-001', $bank->instructions(Money::ofMinor(100, 'MVR'))['account']);
        // No USD account of their own: nothing, and never Rihla's.
        $this->assertNull($bank->instructions(Money::ofMinor(100, 'USD'))['account']);
        $this->assertFalse($bank->hasAccountFor('USD'));
    }

    public function test_references_carry_the_companys_letters(): void
    {
        $rihlas = Booking::factory()->create();
        $this->company->forceFill(['reference_prefix' => 'CRL'])->save();

        $context = app(CompanyContext::class);
        $booking = $context->as($this->company->id, fn () => Booking::factory()->create());
        $payment = $context->as($this->company->id, fn () => Payment::factory()->create(['payable_id' => $booking->id]));

        $this->assertStringStartsWith('RIH-B-', (string) $rihlas->fresh()?->reference);
        $this->assertStringStartsWith('CRL-B-', (string) $context->as($this->company->id, fn () => $booking->fresh()?->reference));
        $this->assertStringStartsWith('CRL-P-', (string) $context->as($this->company->id, fn () => $payment->fresh()?->reference));
    }

    public function test_the_owner_builds_the_team_and_the_company_always_keeps_an_owner(): void
    {
        $this->inPanel();

        Livewire::test(Team::class)
            ->callAction('add', ['name' => 'Hassan', 'email' => 'Hassan@Coral.example', 'role' => Access::BOOKING_STAFF])
            ->assertHasNoActionErrors();

        $hassan = User::where('email', 'hassan@coral.example')->sole();
        $this->assertSame(Access::BOOKING_STAFF, $hassan->roleAtCompany($this->company));
        Notification::assertSentTo($hassan, ResetPassword::class);

        $owners = CompanyMembership::where('company_id', $this->company->id)->where('role', CompanyRole::OWNER)->sole();

        // The last Owner can be neither demoted nor removed.
        Livewire::test(Team::class)->callTableAction('remove', $owners);
        Livewire::test(Team::class)->callTableAction('changeRole', $owners, ['role' => Access::FINANCE]);
        $this->assertSame(CompanyRole::OWNER, $owners->fresh()?->role);

        // Somebody taken off the team loses the panel at once.
        $membership = CompanyMembership::where('user_id', $hassan->id)->sole();
        Livewire::test(Team::class)->callTableAction('remove', $membership);
        $this->assertNull(CompanyMembership::find($membership->id));
        $this->actingAs($hassan)->get('/company/'.$this->company->slug)->assertForbidden();
    }

    public function test_an_owner_cannot_reach_another_companys_member_by_id(): void
    {
        Notification::fake();
        $pearl = app(AddCompany::class)('Pearl Umrah', 'Pearl Owner', 'owner@pearl.example');
        $theirOwner = CompanyMembership::where('company_id', $pearl->id)->sole();

        $this->inPanel();

        Livewire::test(Team::class)
            ->assertCanNotSeeTableRecords([$theirOwner]);
    }
}
