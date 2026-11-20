<?php

namespace Modules\Companies\Tests;

use App\Filament\Pages\NeedsAttention;
use App\Filament\Resources\Partners\Pages\ListPartners;
use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use App\Support\Alert;
use App\Support\Alerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Companies\Services\AddCompany;
use Tests\TestCase;

/**
 * An Umrah company's licence running out (companies plan, Phase 5b): the
 * date sits on the company's record, which nobody opens until something has
 * gone wrong, so Rihla's admin is told on the Needs attention page.
 */
class CompanyLicenceAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function company(string $name, string $email, ?string $expires): Partner
    {
        $company = app(AddCompany::class)($name, 'Owner', $email);
        $company->forceFill(['licence_expires_on' => $expires])->save();

        return $company->fresh();
    }

    private function alert(): ?Alert
    {
        return Alerts::all()->firstWhere('key', 'company.licence');
    }

    public function test_a_licence_a_year_away_or_unrecorded_raises_nothing(): void
    {
        $this->company('Coral Umrah', 'coral@example.com', now()->addYear()->toDateString());
        $this->company('Reef Umrah', 'reef@example.com', null);

        $this->assertNull($this->alert());
    }

    public function test_a_licence_expiring_within_a_month_is_worth_knowing(): void
    {
        $coral = $this->company('Coral Umrah', 'coral@example.com', now()->addDays(20)->toDateString());

        $this->assertSame('expiring', $coral->licenceStanding());
        $alert = $this->alert();
        $this->assertNotNull($alert);
        $this->assertSame(Alert::WORTH_KNOWING, $alert->severity);
        $this->assertStringContainsString('Coral Umrah', $alert->detail);
    }

    public function test_an_expired_licence_is_urgent_and_a_closed_company_is_left_out(): void
    {
        $coral = $this->company('Coral Umrah', 'coral@example.com', now()->subDay()->toDateString());
        $this->company('Reef Umrah', 'reef@example.com', now()->subMonth()->toDateString())
            ->forceFill(['status' => Partner::STATUS_CLOSED])->save();

        $this->assertSame('expired', $coral->licenceStanding());
        $alert = $this->alert();
        $this->assertNotNull($alert);
        $this->assertSame(Alert::URGENT, $alert->severity);
        $this->assertSame('1 Umrah company is trading on an expired licence', $alert->headline);
        $this->assertStringNotContainsString('Reef Umrah', $alert->detail);
    }

    public function test_only_somebody_who_can_edit_companies_is_shown_it(): void
    {
        $this->company('Coral Umrah', 'coral@example.com', now()->subDay()->toDateString());
        $leader = User::factory()->create()->assignRole(Access::TOUR_LEADER);
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $this->assertNull(Alerts::for($leader)->firstWhere('key', 'company.licence'));

        $this->actingAs($admin)->get(NeedsAttention::getUrl())
            ->assertSuccessful()
            ->assertSee('trading on an expired licence');
    }

    public function test_the_partners_list_shows_the_licence_date(): void
    {
        $coral = $this->company('Coral Umrah', 'coral@example.com', now()->addDays(10)->toDateString());
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        Livewire::actingAs($admin)->test(ListPartners::class)
            ->assertCanSeeTableRecords([$coral])
            ->assertTableColumnFormattedStateSet('licence_expires_on', $coral->licence_expires_on?->format('j M Y'), $coral);
    }
}
