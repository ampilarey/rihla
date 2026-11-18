<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Costs\DepartureCostResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Resources\Permits\NusukPermitResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\Tasks\CrmTaskResource;
use App\Filament\Resources\Visas\VisaApplicationResource;
use App\Models\Booking;
use App\Models\Card;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\DepartureCost;
use App\Models\Document;
use App\Models\Enquiry;
use App\Models\Incident;
use App\Models\NusukPermit;
use App\Models\Package;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Person;
use App\Models\Quotation;
use App\Models\Room;
use App\Models\User;
use App\Models\VisaApplication;
use App\Providers\Filament\CompanyPanelProvider;
use App\Services\Companies\AddCompany;
use App\Services\Payments\SlipVault;
use App\Support\Access;
use App\Support\CompanyContext;
use App\Support\CompanyRequest;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 2b of docs/UMRAH_COMPANIES_PLAN.md: every Umrah screen Rihla's staff
 * work on also opens in a company's own panel, for that company.
 */
class CompanyPanelScreensTest extends TestCase
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

    public function test_every_screen_opens_for_the_owner(): void
    {
        $this->actingAs($this->owner);
        $failed = [];

        foreach (CompanyPanelProvider::RESOURCES as $resource) {
            $url = $resource::getUrl('index', panel: 'company', tenant: $this->company);
            $status = $this->get($url)->status();
            if ($status !== 200) {
                $failed[] = "{$resource} {$url} → {$status}";
            }
        }

        foreach (CompanyPanelProvider::PAGES as $page) {
            $url = $page::getUrl(panel: 'company', tenant: $this->company);
            $status = $this->get($url)->status();
            if ($status !== 200) {
                $failed[] = "{$page} {$url} → {$status}";
            }
        }

        $this->assertSame([], $failed);
    }

    public function test_prints_and_downloads_open_for_the_company_that_owns_them(): void
    {
        $context = app(CompanyContext::class);
        $booking = $context->as($this->company->id, fn () => Booking::factory()->create());
        $payment = $context->as($this->company->id, fn () => Payment::factory()->create(['payable_id' => $booking->id]));
        $rihlas = Booking::factory()->create();

        $this->actingAs($this->owner);
        $as = ['company' => $this->company->slug];

        // From their panel, links carry the company and open.
        $this->get(route('lists.manifest', ['departure' => $booking->departure_id] + $as))->assertOk();
        $this->get(route('staff.invoice', ['booking' => $booking->id] + $as))->assertOk();

        // Without it the request is Rihla's, and a company member is nobody there.
        $this->get(route('lists.manifest', ['departure' => $booking->departure_id]))->assertForbidden();

        // Naming their own company does not reach Rihla's departure.
        $this->get(route('lists.manifest', ['departure' => $rihlas->departure_id] + $as))->assertNotFound();

        // A company slug is not a credential: Rihla's own Super Admin, who is
        // no member, is answered as if the company did not exist.
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);
        $this->actingAs($admin)->get(route('lists.manifest', ['departure' => $booking->departure_id] + $as))->assertNotFound();

        // A link made on a company's screen carries the company itself.
        Filament::setCurrentPanel('company');
        Filament::setTenant($this->company);
        $this->assertStringContainsString('company=coral-umrah', CompanyRequest::route('staff.receipt', ['payment' => $payment]));
        $this->assertStringContainsString('company=coral-umrah', app(SlipVault::class)->downloadUrl($payment));
    }

    public function test_a_card_scanned_by_a_company_opens_in_their_panel(): void
    {
        $rihlas = (new Card)->forceFill(['qr_token' => 'abc', 'company_id' => Partner::rihla()?->id]);
        $theirs = (new Card)->forceFill(['qr_token' => 'xyz', 'company_id' => $this->company->id]);

        $this->assertStringEndsWith('/staff/scan?t=abc', $rihlas->url());
        $this->assertStringEndsWith('/company/coral-umrah/scan?t=xyz', $theirs->url());
    }

    public function test_a_package_made_in_the_companys_panel_is_the_companys(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('company');
        Filament::setTenant($this->company);
        app(CompanyContext::class)->use($this->company->id);

        Livewire::test(CreatePackage::class)
            ->fillForm([
                'slug' => 'ramadan-umrah',
                'title' => ['en' => 'Ramadan Umrah'],
                'summary' => ['en' => 'Fourteen nights.'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $package = app(CompanyContext::class)->acrossEveryCompany(fn () => Package::sole());
        $this->assertSame($this->company->id, $package->company_id);
        $this->assertNull(app(CompanyContext::class)->as((int) Partner::rihla()?->id, fn () => Package::first()), "Rihla's panel never sees it.");
    }

    public function test_the_phone_bar_points_at_the_companys_own_screens(): void
    {
        $this->actingAs($this->owner)
            ->get('/company/'.$this->company->slug)
            ->assertOk()
            ->assertSee('/company/coral-umrah/bookings', false)
            ->assertDontSee('/staff/bookings', false);
    }

    public function test_after_the_second_step_a_company_owner_lands_in_their_panel(): void
    {
        $this->assertStringEndsWith('/company/coral-umrah', $this->owner->homeUrl());
        $this->assertStringEndsWith('/staff', User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER)->homeUrl());
    }

    /**
     * The lists themselves, which load in their own Livewire request: a page
     * answers 200 while a table column throws (AGENTS.md, Pulse). Each list
     * is rendered with one record of the company's and one of Rihla's.
     */
    public function test_every_list_renders_the_companys_records_and_not_rihlas(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('company');
        Filament::setTenant($this->company);
        app(CompanyContext::class)->use($this->company->id);

        $makers = [
            BookingResource::class => fn () => Booking::factory()->create(),
            CustomerResource::class => fn () => Customer::factory()->create(),
            PackageResource::class => fn () => Package::factory()->create(),
            PaymentResource::class => fn () => Payment::factory()->create(),
            DocumentResource::class => fn () => Document::factory()->create(),
            VisaApplicationResource::class => fn () => VisaApplication::factory()->create(),
            NusukPermitResource::class => fn () => NusukPermit::factory()->create(),
            EnquiryResource::class => fn () => Enquiry::factory()->create(),
            QuotationResource::class => fn () => Quotation::factory()->create(),
            CrmTaskResource::class => fn () => CrmTask::factory()->create(),
            DepartureCostResource::class => fn () => DepartureCost::factory()->create(),
            RoomResource::class => fn () => Room::factory()->create(),
            IncidentResource::class => fn () => Incident::factory()->create(),
            PersonResource::class => fn () => Person::factory()->create(),
        ];

        foreach ($makers as $resource => $make) {
            $theirs = $make();
            $rihlas = app(CompanyContext::class)->as((int) Partner::rihla()?->id, $make);

            Livewire::test($resource::getPages()['index']->getPage())
                ->assertOk()
                ->assertCanNotSeeTableRecords([$rihlas]);

            $this->assertSame($this->company->id, $theirs->getAttribute('company_id'), $resource);
        }
    }
}
