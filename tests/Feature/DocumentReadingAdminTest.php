<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Payment;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use App\Services\Payments\Drivers\BankTransfer;
use App\Services\Payments\SlipVault;
use App\Support\Access;
use App\Support\DepartureReadiness;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Document reading on the screens — batch B from Hijaz: an upload is read,
 * the desk accepts it from the list, a photo is checked, a slip is read for
 * Finance, and the board names a card that disagrees with its passport.
 */
class DocumentReadingAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    /**
     * A mounted action's modal is a Livewire partial: Filament renders it
     * into the response's effects, and the component's own HTML — what
     * assertSee reads — never carries it. So the modal is read from there.
     */
    private static function modalHtml(Testable $page): string
    {
        return implode('', (array) ($page->effects['partials'] ?? []));
    }

    /** @param  list<string>  $words */
    private function assertModalSays(Testable $page, array $words): void
    {
        $modal = self::modalHtml($page);
        $this->assertNotSame('', $modal, 'No modal was rendered.');

        foreach ($words as $text) {
            $this->assertStringContainsString(e($text), $modal, "The modal does not say: {$text}");
        }
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function traveller(): Traveller
    {
        return Traveller::factory()->for(Customer::factory()->create(['name' => 'Aminath Ali']))->create([
            'full_name' => 'Aminath Ali',
            'passport_number' => null,
            'date_of_birth' => null,
            'nationality' => 'MV',
        ]);
    }

    /** The vision reader, answering with a passport. */
    private function visionReads(array $json): void
    {
        config(['documents.reader' => 'anthropic', 'documents.anthropic.key' => 'sk-test']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($json)]]])]);
    }

    public function test_an_upload_is_read_and_the_visa_desk_accepts_it_from_the_list(): void
    {
        [$first, $second] = DocumentReadingTest::mrzLines();
        $this->visionReads([
            'full_name' => 'AMINATH ALI', 'passport_number' => 'P1234567', 'nationality' => 'MDV', 'gender' => 'female',
            'date_of_birth' => '1986-03-02', 'passport_expiry' => '2031-05-14', 'passport_issuing_country' => 'MDV',
            'mrz' => "{$first}\n{$second}", 'confidence' => ['full_name' => 0.5],
        ]);

        $desk = $this->staff(Access::BOOKING_STAFF);
        $visa = $this->staff(Access::VISA_STAFF);
        $traveller = $this->traveller();

        $this->actingAs($desk);
        $version = app(DocumentWallet::class)->store($traveller, UploadedFile::fake()->image('passport.jpg', 1200, 800), Document::IDENTITY, Document::PASSPORT);
        $document = $version->document;

        $this->assertTrue($document->latestReading()?->hasProposal(), 'The upload was read on the way in.');

        // The desk sees it waiting and cannot accept it; verifying documents is Visa Staff's.
        Livewire::actingAs($desk)->test(ListDocuments::class)
            ->assertCanSeeTableRecords([$document])
            ->assertSee('To accept')
            ->assertTableActionHidden('acceptReading', $document);

        $page = Livewire::actingAs($visa)->test(ListDocuments::class)
            ->filterTable('reading')
            ->assertCanSeeTableRecords([$document])
            ->assertTableActionVisible('acceptReading', $document)
            ->mountTableAction('acceptReading', $document);

        $this->assertModalSays($page, ['The MRZ check digits pass.', 'unsure of: full name', 'Now: Aminath Ali', 'Now: blank']);

        $page->setTableActionData([
            'full_name' => '',
            'passport_number' => 'P1234567',
            'gender' => 'female',
            'date_of_birth' => '1986-03-02',
            'passport_expiry' => '2031-05-14',
            'nationality' => 'MDV',
            'passport_issuing_country' => 'MDV',
            'verify' => true,
        ])->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Reading accepted');

        $traveller->refresh();
        $this->assertSame('P1234567', $traveller->passport_number);
        $this->assertSame('MV', $traveller->nationality);
        $this->assertSame('Aminath Ali', $traveller->full_name);
        $this->assertSame('2031-05-14', $traveller->passport_expiry->toDateString());
        $this->assertSame(Document::VERIFIED, $document->fresh()->status);
        $this->assertSame(DocumentExtraction::ACCEPTED, $document->fresh()->latestReading()?->status);

        Livewire::actingAs($visa)->test(ListDocuments::class)
            ->assertSee('Accepted')
            ->assertTableActionHidden('acceptReading', $document)
            ->assertTableActionVisible('readAgain', $document);

        // Reading again proposes afresh; setting it aside writes nothing.
        Livewire::actingAs($visa)->test(ListDocuments::class)
            ->callTableAction('readAgain', $document)
            ->assertNotified('7 fields proposed');
        $this->assertTrue($document->fresh()->latestReading()?->hasProposal());

        Livewire::actingAs($visa)->test(ListDocuments::class)
            ->callTableAction('ignoreReading', $document)
            ->assertNotified('Reading set aside');
        $this->assertSame(DocumentExtraction::REJECTED, $document->fresh()->latestReading()?->status);
        $this->assertSame('P1234567', $traveller->fresh()->passport_number);
    }

    public function test_a_failed_mrz_and_a_duplicate_are_said_before_anybody_accepts(): void
    {
        [$first, $second] = DocumentReadingTest::mrzLines();
        $wrong = substr_replace($second, '860303', 13, 6);
        $this->visionReads(['full_name' => 'AMINATH ALI', 'passport_number' => 'P1234567', 'date_of_birth' => '1986-03-03', 'mrz' => "{$first}\n{$wrong}"]);

        $visa = $this->staff(Access::VISA_STAFF);
        $this->actingAs($visa);
        Traveller::factory()->for(Customer::factory()->create(['name' => 'Hassan Ibrahim']))->create(['full_name' => 'Aminath Ali', 'passport_number' => 'P1234567']);
        $traveller = $this->traveller();
        $document = app(DocumentWallet::class)->store($traveller, UploadedFile::fake()->image('passport.jpg', 1200, 800), Document::IDENTITY, Document::PASSPORT)->document;

        $page = Livewire::actingAs($visa)->test(ListDocuments::class)
            ->mountTableAction('acceptReading', $document);

        $this->assertModalSays($page, ['The MRZ check digits FAIL', 'Already on file for Aminath Ali (booked by Hassan Ibrahim)']);
    }

    public function test_nothing_is_read_when_no_reader_can_and_the_buttons_stay_away(): void
    {
        config(['documents.reader' => 'manual']);
        $visa = $this->staff(Access::VISA_STAFF);
        $this->actingAs($visa);
        $document = app(DocumentWallet::class)->store($this->traveller(), UploadedFile::fake()->image('passport.jpg'), Document::IDENTITY, Document::PASSPORT)->document;

        $this->assertNull($document->latestReading());

        Livewire::actingAs($visa)->test(ListDocuments::class)
            ->assertCanSeeTableRecords([$document])
            ->assertTableActionHidden('acceptReading', $document)
            ->assertTableActionHidden('readAgain', $document);
    }

    public function test_a_photograph_is_checked_on_upload_and_the_problems_shown(): void
    {
        $visa = $this->staff(Access::VISA_STAFF);
        $this->actingAs($visa);
        $traveller = $this->traveller();

        $bad = app(DocumentWallet::class)->store($traveller, UploadedFile::fake()->createWithContent('photo.png', DocumentReadingTest::png(200, 100)), Document::IDENTITY, Document::PHOTO)->document;
        $reading = $bad->latestReading();
        $this->assertSame('photo-check', $reading?->reader);
        $this->assertStringContainsString('landscape', (string) $reading?->error);
        $this->assertFalse($reading?->hasProposal(), 'A photo check is something to read, not something to accept.');

        $good = app(DocumentWallet::class)->store(Traveller::factory()->for($traveller->customer)->create(), UploadedFile::fake()->createWithContent('photo.png', DocumentReadingTest::png(600, 800)), Document::IDENTITY, Document::PHOTO)->document;
        $this->assertNull($good->latestReading()?->error);

        Livewire::actingAs($visa)->test(ListDocuments::class)
            ->assertSee('Photo: check')
            ->assertSee('Photo looks right')
            ->assertTableActionHidden('acceptReading', $bad);
    }

    public function test_a_slip_is_read_when_attached_and_finance_sees_what_it_says(): void
    {
        $this->visionReads(['amount' => '10000.00', 'currency' => 'MVR', 'reference' => 'BML123456', 'paid_on' => '2026-10-20']);
        $finance = $this->staff(Access::FINANCE);
        $this->actingAs($finance);

        $departure = Departure::factory()->withSeats(20)->create(['date_start' => now()->addDays(30), 'date_end' => now()->addDays(44)]);
        $traveller = $this->traveller();
        $booking = Booking::factory()->create(['customer_id' => $traveller->customer_id, 'departure_id' => $departure->getKey(), 'seats' => 1]);
        $booking->forceFill(['status' => Booking::CONFIRMED, 'total_minor' => 1_000_000])->save();

        $payment = app(BankTransfer::class)->start($booking, Money::ofMajor(10000, 'MVR'));
        app(SlipVault::class)->attach($payment, UploadedFile::fake()->image('slip.jpg', 800, 1200));

        $payment->refresh();
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->status);
        $this->assertSame('10000.00', $payment->slip_reading['amount']);
        $this->assertSame('BML123456', $payment->slip_reading['reference']);
        $this->assertSame('anthropic', $payment->slip_reading['read_by']);

        $page = Livewire::actingAs($finance)->test(ListPayments::class)
            ->assertCanSeeTableRecords([$payment])
            ->assertSee('MVR 10,000.00 · ref BML123456 · 2026-10-20')
            ->mountTableAction('reconcile', $payment);

        $this->assertModalSays($page, ['The slip reads: MVR 10,000.00 · ref BML123456 · 2026-10-20.']);

        // The same bytes again are not read again; a different slip is.
        Http::fake(['api.anthropic.com/*' => Http::response('boom', 500)]);
        app(SlipVault::class)->attach($payment, UploadedFile::fake()->image('slip.jpg', 800, 1200));
        $this->assertSame('BML123456', $payment->fresh()->slip_reading['reference'], 'A reader that fails leaves the last reading standing.');
    }

    public function test_the_board_names_a_card_that_disagrees_with_its_passport_until_it_is_explained(): void
    {
        $departure = Departure::factory()->withSeats(20)->create(['date_start' => now()->addDays(30), 'date_end' => now()->addDays(44)]);
        $traveller = $this->traveller();
        $booking = Booking::factory()->create(['customer_id' => $traveller->customer_id, 'departure_id' => $departure->getKey(), 'seats' => 1]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();
        $booking->travellers()->create(['traveller_id' => $traveller->getKey(), 'occupancy' => 'double', 'is_lead' => true]);

        $identity = fn (): array => array_values(array_filter(
            DepartureReadiness::concerns($departure),
            fn (array $concern): bool => str_contains($concern['headline'], 'disagree'),
        ));

        $this->assertSame([], $identity());

        $traveller->forceFill(['identity_mismatches' => [['field' => 'date_of_birth', 'id_card' => '1986-03-03', 'passport' => '1986-03-02', 'explained' => false, 'note' => null]]])->save();
        $concerns = $identity();
        $this->assertCount(1, $concerns);
        $this->assertSame(DepartureReadiness::ATTENTION, $concerns[0]['severity']);
        $this->assertSame('An ID card and a passport disagree', $concerns[0]['headline']);
        $this->assertStringContainsString('Aminath Ali (date of birth)', $concerns[0]['detail']);

        $traveller->forceFill(['identity_mismatches' => [['field' => 'date_of_birth', 'id_card' => '1986-03-03', 'passport' => '1986-03-02', 'explained' => true, 'note' => 'Card misprinted']]])->save();
        $this->assertSame([], $identity());
    }

    public function test_forgetting_a_customer_blanks_what_the_readers_wrote_down(): void
    {
        $this->actingAs($this->staff(Access::VISA_STAFF));
        $traveller = $this->traveller();
        $version = app(DocumentWallet::class)->store($traveller, UploadedFile::fake()->image('passport.jpg'), Document::IDENTITY, Document::PASSPORT);
        $version->extractions()->create(['reader' => 'test', 'fields' => ['passport_number' => 'P1234567'], 'raw_text' => 'P1234567', 'status' => DocumentExtraction::PROPOSED, 'created_at' => now()]);
        $traveller->forceFill(['national_id' => 'A123456', 'identity_mismatches' => [['field' => 'name']]])->save();

        $this->artisan('data:forget', ['customer' => $traveller->customer_id])->assertSuccessful();

        $reading = DocumentExtraction::query()->first();
        $this->assertNull($reading->fields);
        $this->assertNull($reading->raw_text);
        $this->assertNull($traveller->fresh()->national_id);
        $this->assertNull($traveller->fresh()->identity_mismatches);
    }
}
