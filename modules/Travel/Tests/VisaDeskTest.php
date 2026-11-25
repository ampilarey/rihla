<?php

namespace Modules\Travel\Tests;

use App\Exceptions\DeskRefusal;
use App\Models\Customer;
use App\Models\Notice;
use App\Models\User;
use App\Support\Access;
use App\Support\Services;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Travel\Filament\Resources\TravelRequests\Pages\ManageTravelRequests;
use Modules\Travel\Filament\Resources\TravelVisaApplications\Pages\ManageTravelVisaApplications;
use Modules\Travel\Filament\Resources\VisaProducts\Pages\ManageVisaProducts;
use Modules\Travel\Http\Controllers\TravelController;
use Modules\Travel\Models\TravelRequest;
use Modules\Travel\Models\TravelVisaApplication;
use Modules\Travel\Models\VisaProduct;
use Modules\Travel\Services\TravelRequests;
use Modules\Travel\Services\VisaDesk;
use Tests\TestCase;

/**
 * Travel agency A3 (docs/TRAVEL_AGENCY_PLAN.md): the visa list staff keep,
 * the checklist a customer works through on their trip page, and the
 * application from collecting to a decision.
 */
class VisaDeskTest extends TestCase
{
    use RefreshDatabase;

    private TravelRequest $trip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00'));
        Services::save(['travel' => Services::ON]);
        Storage::fake((string) config('documents.disk'));

        $this->trip = app(TravelRequests::class)->submit([
            'wants_flight' => false, 'wants_hotel' => false, 'wants_visa' => true, 'destination' => 'United Kingdom',
            'depart_on' => '2027-02-01', 'adults' => 1, 'contact_name' => 'Aishath Nazeer', 'contact_phone' => '7781111',
        ]);
    }

    private function manager(): User
    {
        return User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);
    }

    private function ukVisitor(): VisaProduct
    {
        return VisaProduct::create([
            'country' => 'United Kingdom', 'visa_type' => 'Standard Visitor', 'documents' => ['Passport', 'Bank statement'],
            'processing_days' => 15, 'fee_minor' => 250000, 'currency' => 'MVR', 'is_active' => true,
        ]);
    }

    public function test_staff_keep_the_list_and_the_public_page_shows_it(): void
    {
        Livewire::actingAs($this->manager())->test(ManageVisaProducts::class)
            ->callAction('create', data: [
                'country' => 'Sri Lanka', 'visa_type' => 'ETA, 30 days', 'documents' => ['Passport', 'Return ticket'],
                'processing_days' => 2, 'fee' => 800, 'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $visa = VisaProduct::query()->sole();
        $this->assertSame(80000, $visa->fee_minor);
        $this->assertSame(['Passport', 'Return ticket'], $visa->documents);

        $this->get('/en/travel')->assertOk()
            ->assertSee('Visas we handle')->assertSee('Sri Lanka — ETA, 30 days')->assertSee('MVR 800')->assertSee('Passport, Return ticket');

        Livewire::actingAs(User::factory()->create()->assignRole(Access::BOOKING_STAFF))->test(ManageVisaProducts::class)
            ->assertActionHidden('create');
    }

    public function test_an_application_goes_from_the_checklist_to_approved(): void
    {
        $product = $this->ukVisitor();
        $desk = User::factory()->create()->assignRole(Access::BOOKING_STAFF);

        Livewire::actingAs($desk)->test(ManageTravelRequests::class)
            ->callTableAction('visa', $this->trip, data: ['product' => $product->id, 'applicant' => 'AISHATH NAZEER'])
            ->assertNotified('Visa application started');

        $application = TravelVisaApplication::query()->sole();
        $this->assertSame(TravelVisaApplication::COLLECTING, $application->status);
        $this->assertCount(2, $application->checklist);

        // The list changing later does not change what she was asked for.
        $product->update(['documents' => ['Passport', 'Bank statement', 'Employer letter']]);

        $page = TravelController::statusUrl($this->trip);
        $this->get($page)->assertOk()->assertSee('Visa: United Kingdom — Standard Visitor for AISHATH NAZEER')->assertSee('Bank statement');

        // She sends the passport from her page; the bank statement is handed in on paper.
        $this->post(route('travel.requests.visa-document', ['locale' => 'en', 'key' => $this->trip->status_key, 'application' => $application->id, 'item' => 0]), [
            'file' => UploadedFile::fake()->createWithContent('passport.pdf', '%PDF passport'),
        ])->assertRedirect($page);
        $this->assertSame(1, $application->fresh()?->received());
        $this->assertSame(1, $application->documents()->count(), 'Kept against the application…');
        $this->assertSame(0, $this->trip->documents()->count(), '…not shown as something we issued.');

        Livewire::actingAs($desk)->test(ManageTravelVisaApplications::class)
            ->assertTableActionHidden('submit', $application)
            ->callTableAction('tick', $application, data: ['item' => 1])
            ->callTableAction('open', $application->fresh(), data: ['document' => $application->documents()->value('id')])
            ->assertFileDownloaded('passport.pdf');

        Livewire::actingAs($desk)->test(ManageTravelVisaApplications::class)
            ->callTableAction('submit', $application->fresh())
            ->callTableAction('approve', $application->fresh(), data: ['note' => 'Collect the passport from the VFS centre.']);

        $application->refresh();
        $this->assertSame(TravelVisaApplication::APPROVED, $application->status);
        $this->assertTrue(Notice::query()->where('kind', Notice::TRAVEL_VISA_DECIDED)->exists());
        $this->get($page)->assertSee('Approved')->assertSee('Collect the passport from the VFS centre.');
    }

    public function test_nothing_is_submitted_until_everything_is_in_and_a_refusal_says_why(): void
    {
        $desk = app(VisaDesk::class);
        $application = $desk->open($this->trip, $this->ukVisitor(), 'Aishath Nazeer', null);

        try {
            $desk->submit($application, null);
            $this->fail('An incomplete application was submitted.');
        } catch (DeskRefusal $refusal) {
            $this->assertSame('0 of 2 documents are in. Collect the rest first.', $refusal->getMessage());
        }

        $desk->tick($application, 0, null);
        $desk->tick($application->fresh(), 1, null);
        $desk->submit($application->fresh(), null);

        try {
            $desk->tick($application->fresh(), 0, null);
            $this->fail('A document was collected after submission.');
        } catch (DeskRefusal) {
        }

        $this->expectExceptionMessage('Say why it was refused');
        $desk->decide($application->fresh(), false, '', null);
    }

    public function test_a_customer_cannot_upload_to_another_trips_application(): void
    {
        $other = app(TravelRequests::class)->submit([
            'wants_flight' => false, 'wants_hotel' => false, 'wants_visa' => true, 'destination' => 'Japan',
            'depart_on' => '2027-02-01', 'adults' => 1, 'contact_name' => 'B', 'contact_phone' => '2',
        ]);
        $theirs = app(VisaDesk::class)->open($other, $this->ukVisitor(), 'B', null);

        $this->post(route('travel.requests.visa-document', ['locale' => 'en', 'key' => $this->trip->status_key, 'application' => $theirs->id, 'item' => 0]), [
            'file' => UploadedFile::fake()->createWithContent('x.pdf', 'x'),
        ])->assertNotFound();
        $this->assertSame(0, $theirs->fresh()?->received());
    }

    public function test_forgetting_the_customer_reaches_the_application_and_its_files(): void
    {
        $customer = Customer::factory()->create();
        $trip = app(TravelRequests::class)->submit([
            'wants_flight' => false, 'wants_hotel' => false, 'wants_visa' => true, 'destination' => 'UK',
            'depart_on' => '2027-02-01', 'adults' => 1, 'contact_name' => 'Aishath Nazeer', 'contact_phone' => '7781111',
        ], $customer);
        $application = app(VisaDesk::class)->open($trip, $this->ukVisitor(), 'Aishath Nazeer', null);
        $sent = app(VisaDesk::class)->receive($application, 0, UploadedFile::fake()->createWithContent('passport.pdf', 'passport'));

        $this->artisan('data:forget', ['customer' => (string) $customer->id])->assertSuccessful();

        $this->assertNotSame('Aishath Nazeer', DB::table('travel_visa_applications')->where('id', $application->id)->value('applicant_name'));
        $this->assertFalse(Storage::disk((string) config('documents.disk'))->exists($sent->path), 'The passport left with the person.');
    }
}
