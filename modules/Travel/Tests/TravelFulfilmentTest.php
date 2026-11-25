<?php

namespace Modules\Travel\Tests;

use App\Exceptions\DeskRefusal;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Notice;
use App\Models\User;
use App\Support\Access;
use App\Support\Money;
use App\Support\Services;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Travel\Filament\Resources\TravelRequests\Pages\ManageTravelRequests;
use Modules\Travel\Http\Controllers\TravelController;
use Modules\Travel\Models\TravelDocument;
use Modules\Travel\Models\TravelRequest;
use Modules\Travel\Services\TravelDocuments;
use Modules\Travel\Services\TravelRequests;
use Tests\TestCase;

/**
 * Travel agency A2 (docs/TRAVEL_AGENCY_PLAN.md): staff upload what they
 * issued for a paid trip, encrypted, and the customer downloads it from
 * their trip page.
 */
class TravelFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00'));
        Services::save(['travel' => Services::ON]);
        Storage::fake((string) config('documents.disk'));
    }

    private function paidTrip(?Customer $customer = null): TravelRequest
    {
        $requests = app(TravelRequests::class);
        $made = $requests->submit([
            'wants_flight' => true, 'wants_hotel' => true, 'wants_visa' => false, 'destination' => 'Kuala Lumpur',
            'depart_on' => '2026-12-20', 'adults' => 2, 'contact_name' => 'Mariyam Saeed', 'contact_phone' => '7795555',
        ], $customer);
        $option = $requests->addOption($made, 'AirAsia, 4-star', null, Money::ofMajor(18500, 'MVR'));
        $requests->sendQuote($made, 3, null);
        $requests->accept($made, $option->id);
        $made->fresh()->storePaidTotal(1850000);

        return $made->fresh();
    }

    public function test_staff_upload_the_ticket_mark_it_issued_and_the_customer_downloads_it(): void
    {
        $made = $this->paidTrip();
        $this->assertSame(TravelRequest::PAID, $made->status);
        $desk = User::factory()->create()->assignRole(Access::BOOKING_STAFF);

        Livewire::actingAs($desk)->test(ManageTravelRequests::class)
            ->assertTableActionHidden('issue', $made)
            ->callTableAction('upload', $made, data: [
                'kind' => 'ticket',
                'label' => 'Flights, both of you',
                'file' => UploadedFile::fake()->createWithContent('e-ticket.pdf', '%PDF-1.4 the e-ticket'),
            ])
            ->assertNotified('Uploaded');

        $document = TravelDocument::query()->sole();
        $raw = Storage::disk((string) config('documents.disk'))->get($document->path);
        $this->assertStringNotContainsString('the e-ticket', (string) $raw, 'The file is ciphertext on disk.');

        Livewire::actingAs($desk)->test(ManageTravelRequests::class)
            ->callTableAction('issue', $made->fresh())
            ->assertNotified('Issued');
        $this->assertSame(TravelRequest::ISSUED, $made->fresh()->status);
        $this->assertTrue(Notice::query()->where('kind', Notice::TRAVEL_ISSUED)->where('noticeable_id', $made->id)->exists());

        $this->get(TravelController::statusUrl($made))->assertOk()
            ->assertSee('Your tickets and documents')->assertSee('Flights, both of you');

        $download = $this->get(route('travel.requests.document', ['locale' => 'en', 'key' => $made->status_key, 'document' => $document->id]));
        $download->assertOk();
        $this->assertSame('%PDF-1.4 the e-ticket', $download->getContent());
        $this->assertStringContainsString('e-ticket.pdf', (string) $download->headers->get('Content-Disposition'));
        $this->assertTrue(AuditLog::query()->where('event', AuditLog::DOWNLOADED)->where('auditable_id', $document->id)->exists());
    }

    public function test_nothing_is_issued_before_payment_and_another_trips_key_opens_nothing(): void
    {
        $requests = app(TravelRequests::class);
        $unpaid = $requests->submit(['wants_flight' => true, 'wants_hotel' => false, 'wants_visa' => false, 'destination' => 'Colombo', 'depart_on' => '2026-12-05', 'adults' => 1, 'contact_name' => 'A', 'contact_phone' => '1']);

        try {
            app(TravelDocuments::class)->attach($unpaid, UploadedFile::fake()->create('t.pdf', 10, 'application/pdf'), 'ticket', '', null);
            $this->fail('A document was issued for an unpaid trip.');
        } catch (DeskRefusal $refusal) {
            $this->assertSame('Documents are issued once the trip is paid.', $refusal->getMessage());
        }

        $paid = $this->paidTrip();
        try {
            app(TravelDocuments::class)->markIssued($paid, null);
            $this->fail('A trip was marked issued with nothing uploaded.');
        } catch (DeskRefusal $refusal) {
            $this->assertSame('Upload the tickets, vouchers or visas first.', $refusal->getMessage());
        }

        $document = app(TravelDocuments::class)->attach($paid, UploadedFile::fake()->createWithContent('v.pdf', 'voucher'), 'voucher', 'Hotel', null);
        $this->get(route('travel.requests.document', ['locale' => 'en', 'key' => $unpaid->status_key, 'document' => $document->id]))->assertNotFound();
    }

    public function test_forgetting_the_customer_deletes_the_issued_files(): void
    {
        $customer = Customer::factory()->create();
        $paid = $this->paidTrip($customer);
        $document = app(TravelDocuments::class)->attach($paid, UploadedFile::fake()->createWithContent('t.pdf', 'ticket'), 'ticket', 'Flights', null);
        $disk = Storage::disk((string) config('documents.disk'));
        $this->assertTrue($disk->exists($document->path));

        $this->artisan('data:forget', ['customer' => (string) $customer->id])->assertSuccessful();

        $this->assertFalse($disk->exists($document->path), 'The ticket left with the person.');
        $this->assertSame('(file deleted)', $document->fresh()?->path);
    }
}
