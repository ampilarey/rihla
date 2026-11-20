<?php

namespace Modules\Umrah\Tests;

use App\Exceptions\DeskRefusal;
use App\Models\Customer;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Umrah\Filament\Resources\Bookings\Pages\EditBooking;
use Modules\Umrah\Models\Agreement;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\Document;
use Modules\Umrah\Models\Traveller;
use Modules\Umrah\Services\Portal\Agreements;
use Modules\Umrah\Services\Portal\Gatekeeper;
use Tests\TestCase;

/**
 * The booking agreement (companies plan, Phase 3h, part 3, from Hijaz):
 * the company's own terms, signed on screen or as a paper copy, frozen as
 * signed — and absent altogether until the company has written terms.
 */
class AgreementTest extends TestCase
{
    use RefreshDatabase;

    /** A real one-pixel PNG, as a browser's canvas would send it. */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Storage::fake('local');
    }

    private function booking(): Booking
    {
        $customer = Customer::factory()->create(['name' => 'Aminath Ali']);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'departure_id' => Departure::factory()->withSeats(10)->create()->id,
            'seats' => 1,
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();
        $booking->travellers()->create(['traveller_id' => Traveller::factory()->for($customer)->create()->id, 'occupancy' => 'quad', 'is_lead' => true]);

        return $booking->fresh();
    }

    private function enter(Booking $booking): void
    {
        $this->get('/en/portal/enter/'.app(Gatekeeper::class)->issue($booking));
    }

    private function terms(?string $words): void
    {
        config(['invoices.terms.note' => $words]);
    }

    public function test_with_no_terms_there_is_no_agreement_anywhere(): void
    {
        $this->terms(null);
        $booking = $this->booking();
        $this->enter($booking);

        $this->get('/en/portal')->assertOk()->assertDontSee('/en/portal/agreement', false);
        $this->get('/en/portal/agreement')->assertNotFound();

        Livewire::actingAs(User::factory()->create()->assignRole(Access::SUPER_ADMIN))
            ->test(EditBooking::class, ['record' => $booking->id])
            ->assertActionHidden('agreement');
    }

    public function test_the_customer_signs_on_screen_and_the_wording_is_frozen_as_signed(): void
    {
        $this->terms('Deposits are not refundable within 30 days of departure.');
        $booking = $this->booking();
        $this->enter($booking);

        $this->get('/en/portal/agreement')->assertOk()->assertSee('Deposits are not refundable within 30 days of departure.');

        // A signature that is not a drawn PNG is refused.
        $this->post('/en/portal/agreement', ['name' => 'Aminath Ali', 'signature' => 'javascript:alert(1)', 'agree' => '1'])
            ->assertSessionHasErrors('signature');

        $this->post('/en/portal/agreement', ['name' => 'Aminath Ali', 'signature' => self::SIGNATURE, 'agree' => '1'])
            ->assertRedirect('/en/portal/agreement');

        $agreement = Agreement::query()->sole();
        $this->assertSame(Agreement::ON_SCREEN, $agreement->method);
        $this->assertSame('Aminath Ali', $agreement->signed_name);
        $this->assertNotNull($agreement->signed_ip);

        // Later terms never change what was signed.
        $this->terms('Entirely different terms.');
        $this->get('/en/portal/agreement')->assertOk()
            ->assertSee('Deposits are not refundable within 30 days of departure.')
            ->assertDontSee('Entirely different terms.');
        $this->get('/en/portal/agreement/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->expectException(DeskRefusal::class);
        app(Agreements::class)->signOnScreen($booking, 'Aminath Ali', self::SIGNATURE, null, null);
    }

    public function test_a_signed_paper_copy_is_kept_with_the_lead_travellers_documents(): void
    {
        $this->terms('Our terms.');
        $booking = $this->booking();
        $this->enter($booking);

        $this->post('/en/portal/agreement/paper', ['file' => UploadedFile::fake()->image('signed.jpg')])
            ->assertRedirect('/en/portal/agreement');

        $agreement = Agreement::query()->sole();
        $this->assertSame(Agreement::PAPER, $agreement->method);

        $document = Document::query()->where('type', Document::AGREEMENT)->sole();
        $this->assertSame($booking->leadTraveller()?->traveller_id, $document->traveller_id);
        $this->assertSame($booking->id, $document->booking_id);
    }

    public function test_the_office_records_a_paper_copy_and_prints_the_signed_agreement(): void
    {
        $this->terms('Our terms.');
        $booking = $this->booking();
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        Livewire::actingAs($admin)->test(EditBooking::class, ['record' => $booking->id])
            ->assertActionVisible('agreement')
            ->callAction('agreement', data: ['scan' => UploadedFile::fake()->create('signed.pdf', 50, 'application/pdf')])
            ->assertHasNoActionErrors()
            ->assertNotified('Signed paper copy recorded');

        $agreement = Agreement::query()->sole();
        $this->assertSame(Agreement::PAPER, $agreement->method);
        $this->assertSame($admin->id, $agreement->recorded_by);

        $this->actingAs($admin)->get(route('staff.agreement', ['booking' => $booking->id]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_forgetting_the_customer_removes_their_signature(): void
    {
        $this->terms('Our terms.');
        $booking = $this->booking();
        $booking->forceFill(['status' => Booking::COMPLETED])->save();
        app(Agreements::class)->signOnScreen($booking, 'Aminath Ali', self::SIGNATURE, '10.0.0.1', 'Phone');

        $this->artisan('data:forget', ['customer' => $booking->customer_id])->assertSuccessful();

        $agreement = Agreement::query()->sole();
        $this->assertNull($agreement->signature);
        $this->assertNull($agreement->signed_ip);
        $this->assertStringNotContainsString('Aminath', (string) $agreement->signed_name);
    }
}
