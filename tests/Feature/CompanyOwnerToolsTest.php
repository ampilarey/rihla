<?php

namespace Tests\Feature;

use App\Filament\Resources\Partners\Pages\EditPartner;
use App\Filament\Resources\Partners\Pages\ListPartners;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Partner;
use App\Models\User;
use App\Services\Companies\AddCompany;
use App\Services\Companies\CompanyUsage;
use App\Services\Telegram\Bot;
use App\Support\Access;
use App\Support\CompanyContext;
use App\Support\CompanyFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rihla's admin's tools for a company (companies plan, Phase 5b): adding it
 * with the licence checked and what was agreed, suspending and reactivating
 * it with a reason, and seeing how much it uses Rihla as counts, never
 * contents.
 */
class CompanyOwnerToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['companies.site_domain' => 'rihla.test']);
        $this->admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    public function test_rihlas_admin_adds_a_company_with_its_licence_and_what_was_agreed(): void
    {
        Livewire::actingAs($this->admin)->test(ListPartners::class)
            ->callAction('addCompany', data: [
                'name' => 'Coral Umrah',
                'owner_name' => 'Aishath Coral',
                'owner_email' => 'coral@example.com',
                'licence_number' => 'MIA-HU-0420',
                'licence_expires_on' => now()->addYear()->toDateString(),
                'licence_register' => 'Ministry of Islamic Affairs register of Hajj and Umrah operators',
                'registration_fee' => '1500',
                'staff_seats' => 4,
                'features' => [CompanyFeatures::BOOKINGS, CompanyFeatures::PORTAL],
            ])
            ->assertHasNoActionErrors();

        $company = Partner::where('name', 'Coral Umrah')->sole();
        $this->assertTrue($company->is_umrah_company);
        $this->assertSame('MIA-HU-0420', $company->licence_number);
        $this->assertSame('Ministry of Islamic Affairs register of Hajj and Umrah operators', $company->licence_register);
        $this->assertSame(150000, $company->registration_fee_minor);
        $this->assertSame(4, $company->staff_seats);
        $this->assertEquals([CompanyFeatures::BOOKINGS => CompanyFeatures::ON, CompanyFeatures::PORTAL => CompanyFeatures::ON], $company->features);
        $this->assertSame($this->admin->id, $company->verified_by);

        // Defaults seeded: an unpublished website, and not live: the fee is
        // agreed but not yet recorded as paid.
        $this->assertNotNull($company->site);
        $this->assertFalse($company->site->isPublished());
        $this->assertFalse($company->site_enabled);
    }

    public function test_a_licence_is_required_to_add_a_company(): void
    {
        Livewire::actingAs($this->admin)->test(ListPartners::class)
            ->callAction('addCompany', data: ['name' => 'Coral Umrah', 'owner_name' => 'Aishath', 'owner_email' => 'coral@example.com'])
            ->assertHasActionErrors(['licence_number' => 'required', 'licence_expires_on' => 'required', 'licence_register' => 'required']);

        $this->assertFalse(Partner::where('name', 'Coral Umrah')->exists());
    }

    public function test_suspending_shuts_everything_and_reactivating_puts_it_back(): void
    {
        $company = app(AddCompany::class)('Coral Umrah', 'Aishath', 'coral@example.com');
        $company->forceFill(['telegram_bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghij'])->save();

        // A reason is required.
        Livewire::actingAs($this->admin)->test(EditPartner::class, ['record' => $company->id])
            ->callAction('suspend', data: ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        Livewire::actingAs($this->admin)->test(EditPartner::class, ['record' => $company->id])
            ->callAction('suspend', data: ['reason' => 'Registration fee unpaid for three months.'])
            ->assertHasNoActionErrors()
            ->assertNotified('Suspended');

        $company = $company->fresh();
        $this->assertTrue($company->isSuspended());
        $this->assertSame('Registration fee unpaid for three months.', $company->suspended_reason);
        app(CompanyContext::class)->as($company->id, fn () => $this->assertNull(Bot::token()));

        Livewire::actingAs($this->admin)->test(EditPartner::class, ['record' => $company->id])
            ->assertActionHidden('suspend')
            ->callAction('reactivate')
            ->assertNotified('Reactivated');

        $company = $company->fresh();
        $this->assertFalse($company->isSuspended());
        $this->assertNull($company->suspended_reason);
    }

    public function test_a_suspended_companys_people_cannot_open_its_panel(): void
    {
        // Its own test: a request into the company panel leaves that panel
        // current in a test's one application, and staff screens after it
        // would render under it.
        $company = app(AddCompany::class)('Coral Umrah', 'Aishath', 'coral@example.com');
        $owner = User::where('email', 'coral@example.com')->sole();
        $company->forceFill(['status' => Partner::STATUS_SUSPENDED, 'suspended_reason' => 'Unpaid.'])->save();

        $this->assertNotSame(200, $this->actingAs($owner)->get('/company/'.$company->slug)->status());

        $company->forceFill(['status' => Partner::STATUS_ACTIVE, 'suspended_reason' => null])->save();
        $this->actingAs($owner)->get('/company/'.$company->slug)->assertOk();
    }

    public function test_usage_is_counts_of_the_companys_own_records_only(): void
    {
        $company = app(AddCompany::class)('Coral Umrah', 'Aishath', 'coral@example.com');

        // Rihla's own booking, which Coral's counts must not include.
        Booking::factory()->create();

        app(CompanyContext::class)->as($company->id, function (): void {
            $departure = Departure::factory()->withSeats(10)->create();
            Booking::factory()->count(2)->create(['departure_id' => $departure->id, 'customer_id' => Customer::factory()->create()->id]);
        });

        $counts = app(CompanyUsage::class)->counts($company);
        $this->assertSame(2, $counts['Bookings']);
        $this->assertSame(1, $counts['Departures']);
        $this->assertSame(1, $counts['Staff']);

        Livewire::actingAs($this->admin)->test(EditPartner::class, ['record' => $company->id])
            ->assertSee('Bookings: <strong>2</strong>', false);
    }
}
