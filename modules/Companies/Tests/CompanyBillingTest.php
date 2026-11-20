<?php

namespace Modules\Companies\Tests;

use App\Filament\Resources\Partners\Pages\EditPartner;
use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use App\Support\Alerts;
use App\Support\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Companies\Filament\RelationManagers\CompanyInvoicesRelationManager;
use Modules\Companies\Models\CompanyInvoice;
use Modules\Companies\Services\AddCompany;
use Modules\Companies\Services\CompanyBilling;
use Modules\Companies\Services\CompanyTeam;
use Tests\TestCase;

/**
 * Billing Umrah companies (the plan's "Later": invoices, reminders,
 * suspension). Every amount comes from the company's record; the run is
 * idempotent; the company is told by e-mail and, whether or not that
 * arrives, in its own panel.
 */
class CompanyBillingTest extends TestCase
{
    use RefreshDatabase;

    private Partner $coral;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00'));
        $this->coral = app(AddCompany::class)('Coral Umrah', 'Aishath', 'coral@example.com');
    }

    private function billing(): CompanyBilling
    {
        return app(CompanyBilling::class);
    }

    /** @return list<string> subjects of every e-mail sent so far */
    private function subjectsSent(): array
    {
        /** @var ArrayTransport $transport */
        $transport = app('mailer')->getSymfonyTransport();

        return $transport->messages()->map(fn ($message): string => (string) $message->getOriginalMessage()->getSubject())->values()->all();
    }

    private function monthly(string $from, int $feeMinor = 250000): Partner
    {
        $this->coral->forceFill(['monthly_fee_minor' => $feeMinor, 'monthly_fee_currency' => 'MVR', 'billing_starts_on' => $from])->save();

        return $this->coral->fresh();
    }

    public function test_the_registration_fee_is_invoiced_once_and_paying_it_records_the_fee_paid(): void
    {
        $this->coral->forceFill(['registration_fee_minor' => 150000, 'registration_fee_currency' => 'MVR'])->save();

        $issued = $this->billing()->issueDue($this->coral->fresh());
        $this->assertCount(1, $issued);
        $invoice = $issued[0];
        $this->assertSame(CompanyInvoice::REGISTRATION, $invoice->kind);
        $this->assertSame(150000, $invoice->amount_minor);
        $this->assertSame('2026-12-03', $invoice->due_on->toDateString(), 'Due fourteen days after issue.');
        $this->assertMatchesRegularExpression('/^RIH-CI-2026-\d{5}$/', $invoice->number);
        $this->assertContains('Invoice '.$invoice->number.' from Rihla', $this->subjectsSent(), 'The Owner is told.');

        // Running again issues nothing.
        $this->assertSame([], $this->billing()->issueDue($this->coral->fresh()));

        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);
        $this->billing()->recordPayment($invoice, $admin, '2026-11-25', 'BML-TRF-1234');

        $this->assertSame(CompanyInvoice::PAID, $invoice->fresh()->state());
        $this->assertSame('2026-11-25', $this->coral->fresh()->registration_paid_at?->toDateString(), 'Paying the invoice is paying the fee: the website can be switched on.');
    }

    public function test_each_whole_month_is_invoiced_once_from_the_month_billing_starts(): void
    {
        $coral = $this->monthly('2026-09-15');

        $issued = $this->billing()->issueDue($coral);
        $this->assertSame(['2026-09-01', '2026-10-01', '2026-11-01'], array_map(fn (CompanyInvoice $i): string => $i->period_start->toDateString(), $issued));
        $this->assertSame('2026-09-30', $issued[0]->period_end?->toDateString());
        $this->assertSame([], $this->billing()->issueDue($coral->fresh()));

        // A cancelled month is not owed, and is not invoiced again.
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);
        $this->billing()->cancel($issued[0], $admin, 'First month free, as agreed.');
        $this->assertSame([], $this->billing()->issueDue($coral->fresh()));

        // The next month arrives.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 06:10'));
        $this->assertCount(1, $this->billing()->issueDue($coral->fresh()));
    }

    public function test_rihla_a_closed_company_and_a_suspended_one_get_no_new_monthly_invoice(): void
    {
        $rihla = Partner::rihla();
        $this->assertNotNull($rihla);
        $rihla->forceFill(['monthly_fee_minor' => 100, 'billing_starts_on' => '2026-01-01'])->save();
        $this->assertSame([], $this->billing()->issueDue($rihla->fresh()));

        $coral = $this->monthly('2026-11-01');
        $coral->forceFill(['status' => Partner::STATUS_SUSPENDED, 'suspended_reason' => 'Unpaid.'])->save();
        $this->assertSame([], $this->billing()->issueDue($coral->fresh()));

        $coral->forceFill(['status' => Partner::STATUS_CLOSED])->save();
        $this->assertSame(0, $this->billing()->run()['issued']);
    }

    public function test_reminders_go_once_before_the_due_date_and_once_when_overdue_and_nobody_is_suspended_by_default(): void
    {
        $invoice = $this->billing()->issueDue($this->monthly('2026-11-01'))[0];

        // Issued today: no reminder on top of the invoice itself.
        $this->assertSame(0, $this->billing()->chase()['reminded']);

        $this->travelTo(CarbonImmutable::parse('2026-11-30 06:10'));
        $this->assertSame(1, $this->billing()->chase()['reminded']);
        $this->assertSame(0, $this->billing()->chase()['reminded'], 'Once.');

        $this->travelTo(CarbonImmutable::parse('2026-12-04 06:10'));
        $this->assertSame(1, $this->billing()->chase()['overdue']);
        $this->assertSame(0, $this->billing()->chase()['overdue'], 'Once.');
        $this->assertContains('Invoice '.$invoice->number.' is overdue', $this->subjectsSent());

        $this->travelTo(CarbonImmutable::parse('2027-03-01 06:10'));
        $this->billing()->chase();
        $this->assertFalse($this->coral->fresh()->isSuspended(), 'Never automatic until the owner decides.');
    }

    public function test_when_configured_a_long_unpaid_invoice_suspends_the_company_with_the_invoice_as_the_reason(): void
    {
        config(['companies.billing.suspend_after_days' => 7]);
        $invoice = $this->billing()->issueDue($this->monthly('2026-11-01'))[0];

        $this->travelTo(CarbonImmutable::parse('2026-12-10 06:10'));
        $this->assertSame(0, $this->billing()->chase()['suspended'], 'Seven days overdue is not yet more than seven.');

        $this->travelTo(CarbonImmutable::parse('2026-12-11 06:10'));
        $this->assertSame(1, $this->billing()->chase()['suspended']);
        $coral = $this->coral->fresh();
        $this->assertTrue($coral->isSuspended());
        $this->assertStringContainsString($invoice->number, (string) $coral->suspended_reason);
    }

    public function test_rihlas_admin_sets_the_monthly_fee_issues_now_and_records_the_payment(): void
    {
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        Livewire::actingAs($admin)->test(EditPartner::class, ['record' => $this->coral->id])
            ->fillForm(['monthly_fee' => '2500', 'monthly_fee_currency' => 'USD', 'billing_starts_on' => '2026-11-01'])
            ->call('save')
            ->assertHasNoFormErrors();

        $coral = $this->coral->fresh();
        $this->assertSame(250000, $coral->monthly_fee_minor);
        $this->assertSame('USD', $coral->monthly_fee_currency);

        Livewire::actingAs($admin)->test(EditPartner::class, ['record' => $coral->id])
            ->callAction('billNow')
            ->assertNotified('1 invoice issued');

        $invoice = CompanyInvoice::query()->sole();
        $this->assertSame('USD', $invoice->currency);

        Livewire::actingAs($admin)
            ->test(CompanyInvoicesRelationManager::class, ['ownerRecord' => $coral, 'pageClass' => EditPartner::class])
            ->assertCanSeeTableRecords([$invoice])
            ->callTableAction('recordPayment', $invoice, data: ['paid_on' => '2026-11-19', 'reference' => 'TRF-88'])
            ->assertNotified('Payment recorded');

        $this->assertSame('TRF-88', $invoice->fresh()->payment_reference);
        $this->assertSame($admin->id, $invoice->fresh()->recorded_by);
    }

    public function test_the_pdf_is_for_the_companys_owners_and_rihlas_staff_only(): void
    {
        $invoice = $this->billing()->issueDue($this->monthly('2026-11-01'))[0];
        $owner = User::where('email', 'coral@example.com')->sole();
        $member = app(CompanyTeam::class)->add($this->coral, 'Mariyam', 'mariyam@example.com', CompanyRole::ROLES[2]);
        $other = app(AddCompany::class)('Reef Umrah', 'Hassan', 'reef@example.com');
        $otherOwner = User::where('email', 'reef@example.com')->sole();
        $staff = User::factory()->create()->assignRole(Access::SUPER_ADMIN);
        $url = route('company-invoices.pdf', $invoice);

        $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->flushSession();
        $this->actingAs($staff)->get($url)->assertOk();
        $this->flushSession();
        $this->actingAs($otherOwner)->get($url)->assertNotFound();
        $this->flushSession();
        $this->actingAs(User::query()->findOrFail($member->user_id))->get($url)->assertNotFound();
        $this->assertNotNull($other);
    }

    public function test_an_overdue_invoice_is_raised_for_rihla_and_shown_to_the_companys_owner(): void
    {
        $invoice = $this->billing()->issueDue($this->monthly('2026-11-01'))[0];
        $this->travelTo(CarbonImmutable::parse('2026-12-05 09:00'));

        $alert = Alerts::all()->firstWhere('key', 'company.invoice.overdue');
        $this->assertNotNull($alert);
        $this->assertStringContainsString('Coral Umrah', $alert->detail);

        $owner = User::where('email', 'coral@example.com')->sole();
        $this->actingAs($owner)->get('/company/'.$this->coral->slug)
            ->assertOk()
            ->assertSee('An invoice from Rihla is overdue')
            ->assertSee($invoice->number);
        $this->actingAs($owner)->get('/company/'.$this->coral->slug.'/details')
            ->assertOk()
            ->assertSee('Invoices from Rihla')
            ->assertSee('Overdue since 3 Dec 2026');
    }

    public function test_a_team_member_who_is_not_an_owner_is_not_shown_the_banner(): void
    {
        $this->billing()->issueDue($this->monthly('2026-11-01'));
        $this->travelTo(CarbonImmutable::parse('2026-12-05 09:00'));
        $member = app(CompanyTeam::class)->add($this->coral, 'Mariyam', 'mariyam@example.com', CompanyRole::ROLES[2]);

        $this->actingAs(User::query()->findOrFail($member->user_id))->get('/company/'.$this->coral->slug)
            ->assertOk()
            ->assertDontSee('An invoice from Rihla is overdue');
    }
}
