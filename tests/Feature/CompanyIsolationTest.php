<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\User;
use App\Support\Access;
use App\Support\CompanyContext;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Content\Filament\Resources\People\PersonResource;
use Modules\Content\Models\Person;
use Modules\Umrah\Filament\Resources\Bookings\BookingResource;
use Modules\Umrah\Filament\Resources\Costs\DepartureCostResource;
use Modules\Umrah\Filament\Resources\Documents\DocumentResource;
use Modules\Umrah\Filament\Resources\Enquiries\EnquiryResource;
use Modules\Umrah\Filament\Resources\Incidents\IncidentResource;
use Modules\Umrah\Filament\Resources\Packages\PackageResource;
use Modules\Umrah\Filament\Resources\Permits\NusukPermitResource;
use Modules\Umrah\Filament\Resources\Quotations\QuotationResource;
use Modules\Umrah\Filament\Resources\Rooms\RoomResource;
use Modules\Umrah\Filament\Resources\Tasks\CrmTaskResource;
use Modules\Umrah\Filament\Resources\Visas\VisaApplicationResource;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\BusGroup;
use Modules\Umrah\Models\CrmTask;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\DepartureCost;
use Modules\Umrah\Models\DepartureHotel;
use Modules\Umrah\Models\Document;
use Modules\Umrah\Models\Enquiry;
use Modules\Umrah\Models\Incident;
use Modules\Umrah\Models\NusukPermit;
use Modules\Umrah\Models\Package;
use Modules\Umrah\Models\Quotation;
use Modules\Umrah\Models\Room;
use Modules\Umrah\Models\Traveller;
use Modules\Umrah\Models\VisaApplication;
use Modules\Umrah\Services\Documents\DocumentWallet;
use Modules\Umrah\Services\Family\Doorkeeper;
use Modules\Umrah\Services\Portal\Gatekeeper;
use Tests\TestCase;

/**
 * Phase 1f of docs/UMRAH_COMPANIES_PLAN.md, the release gate: signed in to
 * Rihla's surfaces with the widest access there is, every screen, file,
 * list and portal link of a second company is refused.
 *
 * A Super Admin is the hardest case, because `Gate::before` grants them
 * everything; if they cannot reach the other company, nobody with less can.
 * Each check first proves the same path reaches *Rihla's* record, so a
 * refusal cannot pass by the page simply being broken.
 */
class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Partner $coral;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        $this->coral = Partner::factory()->create(['name' => 'Coral Umrah', 'kind' => Partner::KIND_AGENCY]);
        $this->admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asCoral(callable $callback): mixed
    {
        return app(CompanyContext::class)->as($this->coral->id, $callback);
    }

    /**
     * Every staff resource over an Umrah record, with how to make one.
     *
     * @return array<string, array{0: class-string<resource>, 1: callable(): Model}>
     */
    private function resources(): array
    {
        return [
            'bookings' => [BookingResource::class, fn () => Booking::factory()->create()],
            'customers' => [CustomerResource::class, fn () => Customer::factory()->create()],
            'packages' => [PackageResource::class, fn () => Package::factory()->create()],
            'payments' => [PaymentResource::class, fn () => Payment::factory()->create()],
            'documents' => [DocumentResource::class, fn () => Document::factory()->create()],
            'enquiries' => [EnquiryResource::class, fn () => Enquiry::factory()->create()],
            'quotations' => [QuotationResource::class, fn () => Quotation::factory()->create()],
            'incidents' => [IncidentResource::class, fn () => Incident::factory()->create()],
            'permits' => [NusukPermitResource::class, fn () => NusukPermit::factory()->create()],
            'visas' => [VisaApplicationResource::class, fn () => VisaApplication::factory()->create()],
            'costs' => [DepartureCostResource::class, fn () => DepartureCost::factory()->create()],
            'people' => [PersonResource::class, fn () => Person::factory()->create()],
            'rooms' => [RoomResource::class, fn () => Room::factory()->create()],
            'tasks' => [CrmTaskResource::class, fn () => CrmTask::factory()->create()],
        ];
    }

    public function test_no_staff_list_holds_another_companys_records(): void
    {
        $this->actingAs($this->admin);

        foreach ($this->resources() as $name => [$resource, $make]) {
            $ours = $make();
            $theirs = $this->asCoral($make);
            $this->assertSame($this->coral->id, $theirs->getAttribute('company_id'), "[{$name}] was not made for the other company.");

            // The query every one of the resource's lists, tabs, filters,
            // searches and record pages starts from. Read directly, because
            // a list's default tab (payments open on "awaiting") would hide
            // Rihla's own record too and make a refusal prove nothing.
            $ids = $resource::getEloquentQuery()->pluck($ours->getQualifiedKeyName())->all();
            $this->assertContains($ours->getKey(), $ids, "[{$name}] does not list Rihla's own record.");
            $this->assertNotContains($theirs->getKey(), $ids, "[{$name}] lists the other company's record.");
        }
    }

    public function test_no_staff_record_page_opens_another_companys_record(): void
    {
        $this->actingAs($this->admin);
        $opened = 0;

        foreach ($this->resources() as $name => [$resource, $make]) {
            foreach (['view', 'edit'] as $page) {
                if (! $resource::hasPage($page)) {
                    continue;
                }

                $ours = $make();
                $theirs = $this->asCoral($make);

                $this->get($resource::getUrl($page, ['record' => $ours]))->assertOk();
                $this->get($resource::getUrl($page, ['record' => $theirs]))->assertNotFound();
                $opened++;
            }
        }

        $this->assertGreaterThanOrEqual(8, $opened, 'The sweep reached fewer record pages than exist.');
    }

    public function test_no_file_of_another_company_can_be_downloaded(): void
    {
        $this->actingAs($this->admin);
        $scan = fn () => app(DocumentWallet::class)->store(
            Traveller::factory()->create(),
            UploadedFile::fake()->createWithContent('passport.pdf', '%PDF-1.4 scan'),
            'travel',
            'passport',
        );

        $ours = $scan();
        $theirs = $this->asCoral($scan);

        $this->get(app(DocumentWallet::class)->downloadUrl($ours))->assertOk();
        // Signed in their own context, as a link they sent would be.
        $this->get($this->asCoral(fn () => app(DocumentWallet::class)->downloadUrl($theirs)))->assertNotFound();
    }

    public function test_no_invoice_receipt_or_list_of_another_company_prints(): void
    {
        $this->actingAs($this->admin);

        $ours = Payment::factory()->create();
        $theirs = $this->asCoral(fn () => Payment::factory()->create());
        $ourBooking = Booking::findOrFail($ours->payable_id);
        $theirBooking = $this->asCoral(fn () => Booking::findOrFail($theirs->payable_id));

        $this->get(route('staff.invoice', $ourBooking))->assertOk();
        $this->get(route('staff.invoice', $theirBooking->id))->assertNotFound();
        $this->get(route('staff.receipt', $theirs->id))->assertNotFound();

        $this->get(route('lists.manifest', $ourBooking->departure_id))->assertOk();
        foreach (['manifest', 'payments', 'documents'] as $list) {
            $this->get(route("lists.{$list}", $theirBooking->departure_id))->assertNotFound();
        }

        $theirBus = $this->asCoral(fn () => BusGroup::create(['departure_id' => $theirBooking->departure_id, 'name' => 'Bus 1']));
        $this->get(route('lists.bus', $theirBus->id))->assertNotFound();

        $theirHotel = $this->asCoral(fn () => DepartureHotel::factory()->create(['departure_id' => $theirBooking->departure_id]));
        $this->get(route('lists.rooms', $theirHotel->id))->assertNotFound();
    }

    public function test_no_portal_link_of_another_company_lets_anybody_in(): void
    {
        $theirBooking = $this->asCoral(fn () => Booking::factory()->create());
        $portal = $this->asCoral(fn () => app(Gatekeeper::class)->issue($theirBooking));
        $family = $this->asCoral(fn () => app(Doorkeeper::class)->issue($theirBooking));

        // Opened on rihla.mv, which works for Rihla's company.
        $this->get(route('portal.enter', ['locale' => 'en', 'token' => $portal]));
        $this->get(route('portal.home', ['locale' => 'en']))->assertRedirect();

        $this->get(route('family.enter', ['locale' => 'en', 'token' => $family]));
        $this->get(route('family.home', ['locale' => 'en']))->assertRedirect();

        // The same links work where they belong, so the refusal above is
        // the company wall and not a broken link.
        $this->assertNotNull($this->asCoral(fn () => app(Gatekeeper::class)->find($portal)));
        $this->assertNull(app(Gatekeeper::class)->find($portal));
        $this->assertNull(app(Doorkeeper::class)->find($family));
    }

    public function test_a_departure_of_another_company_is_never_rihlas_to_change(): void
    {
        $theirs = $this->asCoral(fn () => Departure::factory()->create());

        $this->assertNull(Departure::find($theirs->id));
        $this->assertFalse($this->admin->can('update', $theirs));
        $this->assertFalse($this->admin->can('delete', $theirs));
    }

    public function test_signed_links_do_not_survive_the_wall_either(): void
    {
        // A slip link signed for their payment, opened by Rihla's staff.
        $this->actingAs($this->admin);
        $theirs = $this->asCoral(fn () => Payment::factory()->create());

        $this->get(URL::signedRoute('payments.slip', ['payment' => $theirs->id]))->assertNotFound();
    }
}
