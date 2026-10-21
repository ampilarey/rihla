<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\DocumentVersion;
use App\Models\Traveller;
use App\Models\User;
use App\Models\VisaApplication;
use App\Services\Documents\DocumentWallet;
use App\Services\Documents\Reading\Acceptor;
use App\Services\Documents\Reading\Countries;
use App\Services\Documents\Reading\Duplicates;
use App\Services\Documents\Reading\Extraction;
use App\Services\Documents\Reading\FieldParser;
use App\Services\Documents\Reading\IdentityCheck;
use App\Services\Documents\Reading\Mrz;
use App\Services\Documents\Reading\PdfText;
use App\Services\Documents\Reading\PhotoCheck;
use App\Services\Documents\Reading\ReaderManager;
use App\Services\Documents\Reading\Readers\AnthropicVisionReader;
use App\Services\Documents\Reading\Readers\ManualReader;
use App\Services\Documents\Reading\Readers\TesseractReader;
use App\Services\Visa\VisaDesk;
use App\Support\Access;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Document reading — batch B from Hijaz. The parsers and the readers, and
 * what accepting a reading does to the record; the screens are in
 * DocumentReadingAdminTest.
 */
class DocumentReadingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    /** @return array{string, string} the two MRZ lines of a passport, check-digited */
    public static function mrzLines(string $number = 'P1234567', string $dob = '860302', string $expiry = '310514', string $sex = 'F', string $nationality = 'MDV'): array
    {
        $first = str_pad('P<MDVALI<<AMINATH', 44, '<');
        $numberField = str_pad($number, 9, '<');
        $personal = str_repeat('<', 14);
        $second = $numberField.Mrz::checkDigit($numberField)
            .$nationality
            .$dob.Mrz::checkDigit($dob)
            .$sex
            .$expiry.Mrz::checkDigit($expiry)
            .$personal.Mrz::checkDigit($personal);
        $second .= Mrz::checkDigit(substr($second, 0, 10).substr($second, 13, 7).substr($second, 21, 7).substr($second, 28, 15));

        return [$first, $second];
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
            'gender' => null,
            'nationality' => 'MV',
        ]);
    }

    private function version(Traveller $traveller, string $type = Document::PASSPORT, string $contents = 'a scan'): DocumentVersion
    {
        return app(DocumentWallet::class)->store(
            $traveller,
            UploadedFile::fake()->createWithContent('scan.pdf', $contents),
            Document::IDENTITY,
            $type,
        );
    }

    /** @param  array<string, string>  $fields */
    private function proposed(DocumentVersion $version, array $fields, ?bool $mrzValid = null): DocumentExtraction
    {
        return $version->extractions()->create([
            'reader' => 'test',
            'fields' => $fields,
            'confidence' => array_fill_keys(array_keys($fields), 0.9),
            'mrz_valid' => $mrzValid,
            'status' => DocumentExtraction::PROPOSED,
            'created_at' => now(),
        ]);
    }

    public function test_the_mrz_is_read_and_its_check_digits_catch_a_misread(): void
    {
        [$first, $second] = self::mrzLines();
        $this->assertSame(44, strlen($first));
        $this->assertSame(44, strlen($second));

        $mrz = Mrz::parse("Some visual zone text\n{$first}\n{$second}\n");
        $this->assertNotNull($mrz);
        $this->assertSame('P1234567', $mrz['passport_number']);
        $this->assertSame('1986-03-02', $mrz['date_of_birth']);
        $this->assertSame('2031-05-14', $mrz['expires_on']);
        $this->assertSame('female', $mrz['gender']);
        $this->assertSame('MDV', $mrz['nationality']);
        $this->assertSame('MDV', $mrz['issuing_country']);
        $this->assertSame('ALI', $mrz['surname']);
        $this->assertSame('AMINATH', $mrz['given_names']);
        $this->assertTrue($mrz['valid']);
        $this->assertTrue($mrz['checks']['composite']);

        // A digit read wrong fails its own check and the whole reading.
        $wrong = substr_replace($second, '860303', 13, 6);
        $mrz = Mrz::parse("{$first}\n{$wrong}");
        $this->assertFalse($mrz['valid']);
        $this->assertFalse($mrz['checks']['date_of_birth']);
        $this->assertTrue($mrz['checks']['passport_number']);

        // OCR's O for 0 is put back before checking.
        $smudged = str_replace('860302', '86O3O2', $second);
        $mrz = Mrz::parse("{$first}\n{$smudged}");
        $this->assertTrue($mrz['valid']);
        $this->assertSame('1986-03-02', $mrz['date_of_birth']);

        $this->assertNull(Mrz::parse('nothing like a passport here'));
        $this->assertSame(5, Mrz::checkDigit('AB2134<<<'), 'ICAO 9303 appendix A worked example.');
    }

    public function test_the_field_parser_reads_a_passport_an_id_card_a_visa_and_a_slip(): void
    {
        [$first, $second] = self::mrzLines();
        $passport = new Extraction('test', rawText: "REPUBLIC OF MALDIVES\nPASSPORT\n{$first}\n{$second}");
        FieldParser::apply($passport, Document::PASSPORT);
        $this->assertTrue($passport->mrzValid);
        $this->assertSame('P1234567', $passport->fields['passport_number']);
        $this->assertSame('2031-05-14', $passport->fields['passport_expiry']);
        $this->assertSame('AMINATH ALI', $passport->fields['full_name']);
        $this->assertSame('MDV', $passport->fields['nationality']);
        $this->assertGreaterThan(0.9, $passport->confidence['passport_number']);

        $card = new Extraction('test', rawText: "REPUBLIC OF MALDIVES\nNATIONAL IDENTITY CARD\nName: AMINATH ALI\nA123456\nSex: F\nDate of Birth: 02/03/1986\nExpiry Date: 14/05/2031\nAddress: Daisy Villa, Male");
        FieldParser::apply($card, Document::NATIONAL_ID);
        $this->assertSame('A123456', $card->fields['national_id']);
        $this->assertSame('female', $card->fields['gender']);
        $this->assertSame('1986-03-02', $card->fields['date_of_birth']);
        $this->assertSame('2031-05-14', $card->fields['id_expiry']);
        $this->assertSame('AMINATH ALI', $card->fields['full_name']);

        $visa = new Extraction('test', rawText: "KINGDOM OF SAUDI ARABIA\nVISA NO: 123456789\nPASSPORT NO: P1234567\nNAME: AMINATH ALI\nDATE OF ISSUE: 01/11/2026\nVALID UNTIL: 30/12/2026");
        FieldParser::apply($visa, Document::VISA);
        $this->assertSame('123456789', $visa->fields['number']);
        $this->assertSame('P1234567', $visa->fields['passport_number']);
        $this->assertSame('AMINATH ALI', $visa->fields['full_name']);
        $this->assertSame('2026-11-01', $visa->fields['issued_on']);
        $this->assertSame('2026-12-30', $visa->fields['expires_on']);

        $slip = new Extraction('test', rawText: "Bank of Maldives\nFund transfer\nAmount MVR 10,000.00\nRef: BML123456\nDate 20/10/2026\nRemarks: Umrah booking");
        FieldParser::apply($slip, Document::BANK_SLIP);
        $this->assertSame('10000.00', $slip->fields['amount']);
        $this->assertSame('MVR', $slip->fields['currency']);
        $this->assertSame('BML123456', $slip->fields['reference']);
        $this->assertSame('2026-10-20', $slip->fields['paid_on']);

        $this->assertSame('MV', Countries::iso2('MDV'));
        $this->assertSame('SA', Countries::iso2('sau'));
        $this->assertSame('MV', Countries::iso2('MV'));
        $this->assertNull(Countries::iso2('XXX'), 'A code the table does not know is not guessed.');
    }

    public function test_the_photo_check_names_what_is_wrong_and_passes_a_proper_photo(): void
    {
        $this->assertSame([], PhotoCheck::problems(self::png(600, 800), 'image/png'));

        $problems = PhotoCheck::problems(self::png(200, 100), 'image/png');
        $this->assertCount(2, $problems);
        $this->assertStringContainsString('needs at least 400×500', $problems[0]);
        $this->assertStringContainsString('landscape', $problems[1]);

        $dark = PhotoCheck::problems(self::png(600, 800, 20), 'image/png');
        $this->assertSame(['The background does not look plain and light.'], $dark);

        $this->assertSame(['A photo must be a JPEG or PNG image, not a PDF.'], PhotoCheck::problems('%PDF-1.4', 'application/pdf'));
        $this->assertSame(['The image could not be read.'], PhotoCheck::problems('not an image', 'image/png'));
    }

    public function test_a_pdf_with_a_text_layer_is_read_without_any_ocr(): void
    {
        if (! PdfText::isAvailable()) {
            $this->markTestSkipped('pdftotext is not on this host.');
        }

        $pdf = Pdf::loadHTML('<p>KINGDOM OF SAUDI ARABIA</p><p>VISA NO: 123456789</p><p>PASSPORT NO: P1234567</p><p>VALID UNTIL: 30/12/2026</p>')->output();

        $pages = PdfText::pages($pdf);
        $this->assertCount(1, $pages);
        $this->assertStringContainsString('VISA NO: 123456789', $pages[0]);

        // Whatever reader is configured — here none — the text layer reads.
        $x = app(ReaderManager::class)->run(new ManualReader, $pdf, 'application/pdf', Document::VISA);
        $this->assertSame('pdf-text', $x->reader);
        $this->assertSame('123456789', $x->fields['number']);
        $this->assertSame('P1234567', $x->fields['passport_number']);
    }

    public function test_tesseract_reads_the_machine_readable_zone_off_an_image(): void
    {
        $reader = new TesseractReader;

        if (! $reader->isAvailable()) {
            $this->markTestSkipped('tesseract is not on this host.');
        }

        [$first, $second] = self::mrzLines();
        $x = $reader->read(self::passportImage($first, $second), 'image/png', Document::PASSPORT);

        $this->assertSame('tesseract', $x->reader);
        $this->assertTrue($x->mrzValid, 'The MRZ pass read the two lines; raw: '.$x->rawText);
        $this->assertSame('P1234567', $x->fields['passport_number']);
        $this->assertSame('1986-03-02', $x->fields['date_of_birth']);
    }

    public function test_the_manager_picks_what_can_read_and_records_the_proposal(): void
    {
        $manager = app(ReaderManager::class);

        // Stored while nothing reads, so the upload itself proposes nothing
        // and the readings below are the manager's alone.
        $this->actingAs($this->staff(Access::VISA_STAFF));
        $traveller = $this->traveller();
        $version = $this->version($traveller);
        $this->assertNull($version->document->latestReading());

        config(['documents.reader' => 'manual']);
        $this->assertInstanceOf(ManualReader::class, $manager->reader());
        $this->assertFalse($manager->proposes());

        config(['documents.reader' => 'anthropic', 'documents.anthropic.key' => null]);
        $this->assertInstanceOf(ManualReader::class, $manager->reader(), 'No key, no vision reader.');

        config(['documents.reader' => 'tesseract', 'documents.tesseract.binary' => '/nonexistent/tesseract']);
        $this->assertInstanceOf(ManualReader::class, $manager->reader(), 'No binary, no OCR.');

        config(['documents.reader' => 'auto', 'documents.anthropic.key' => 'sk-test', 'documents.tesseract.binary' => '/nonexistent/tesseract']);
        $this->assertInstanceOf(AnthropicVisionReader::class, $manager->reader());

        // The vision reader's answer is recorded, and the MRZ it reads back is still check-digited here.
        [$first, $second] = self::mrzLines();
        // One fake for both calls: Laravel answers with the first stub that
        // matches, so a second fake of the same URL would never be reached.
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push(['content' => [['type' => 'text', 'text' => json_encode([
            'surname' => 'ALI', 'given_names' => 'AMINATH', 'full_name' => 'AMINATH ALI', 'passport_number' => 'P1234567',
            'nationality' => 'MDV', 'gender' => 'female', 'date_of_birth' => '1986-03-02', 'passport_expiry' => '2031-05-14',
            'passport_issuing_country' => 'MDV', 'mrz' => "{$first}\n{$second}", 'confidence' => ['full_name' => 0.6],
        ])]]])->push('boom', 500)]);

        $extraction = $manager->read($version);

        $this->assertSame('anthropic', $extraction->reader);
        $this->assertTrue($extraction->hasProposal());
        $this->assertTrue($extraction->mrz_valid);
        $this->assertSame('P1234567', $extraction->fields['passport_number']);
        $this->assertSame(['full_name'], $extraction->lowConfidenceFields());
        $this->assertSame($extraction->getKey(), $version->document->fresh()->latestReading()?->getKey());

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/messages') && $request->hasHeader('x-api-key', 'sk-test'));

        // A reader that fails records the failure and proposes nothing.
        $failed = $manager->read($version);
        $this->assertFalse($failed->hasProposal());
        $this->assertNotNull($failed->error);
    }

    public function test_accepting_a_reading_writes_the_traveller_and_the_document_and_nothing_else(): void
    {
        $visa = $this->staff(Access::VISA_STAFF);
        $this->actingAs($visa);
        $traveller = $this->traveller();
        $version = $this->version($traveller);
        $reading = $this->proposed($version, [
            'full_name' => 'AMINATH ALI', 'passport_number' => 'p1234567', 'gender' => 'female', 'date_of_birth' => '1986-03-02',
            'passport_expiry' => '2031-05-14', 'nationality' => 'MDV', 'passport_issuing_country' => 'MDV',
            'surname' => 'ALI', 'given_names' => 'AMINATH', 'medical_notes' => 'should never be written',
        ], mrzValid: true);

        $fields = $reading->fields;
        $fields['full_name'] = '';   // blank: leave the record's name alone

        $traveller = app(Acceptor::class)->accept($reading, $fields, verify: true);

        $this->assertSame('Aminath Ali', $traveller->full_name, 'A blank box leaves the record as it is.');
        $this->assertSame('P1234567', $traveller->passport_number);
        $this->assertSame('female', $traveller->gender);
        $this->assertSame('1986-03-02', $traveller->date_of_birth->toDateString());
        $this->assertSame('2031-05-14', $traveller->passport_expiry->toDateString());
        $this->assertSame('MV', $traveller->nationality, 'Three letters on the passport, two on the record.');
        $this->assertSame('MV', $traveller->passport_issuing_country);
        $this->assertNull($traveller->medical_notes, 'Only the fields a passport may fill are written.');

        $document = $version->document->fresh();
        $this->assertSame('2031-05-14', $document->expires_at->toDateString());
        $this->assertSame(Document::VERIFIED, $document->status);

        $reading->refresh();
        $this->assertSame(DocumentExtraction::ACCEPTED, $reading->status);
        $this->assertTrue($reading->reviewer->is($visa));
        $this->assertSame('p1234567', $reading->accepted_fields['passport_number']);
        $this->assertArrayNotHasKey('full_name', $reading->accepted_fields);

        app(Acceptor::class)->reject($this->proposed($this->version($traveller, Document::NATIONAL_ID), ['national_id' => 'A123456']));
        $this->assertSame(DocumentExtraction::REJECTED, DocumentExtraction::query()->latest('id')->first()->status);
    }

    public function test_an_id_card_that_disagrees_with_the_passport_is_recorded_until_somebody_explains_it(): void
    {
        $this->actingAs($this->staff(Access::VISA_STAFF));
        $traveller = $this->traveller();
        $acceptor = app(Acceptor::class);

        $passport = $this->proposed($this->version($traveller, Document::PASSPORT, 'passport'), ['full_name' => 'AMINATH ALI', 'gender' => 'female', 'date_of_birth' => '1986-03-02']);
        $acceptor->accept($passport, $passport->fields);
        $this->assertSame([], $traveller->fresh()->unexplainedMismatches(), 'One document alone has nothing to disagree with.');

        $card = $this->proposed($this->version($traveller, Document::NATIONAL_ID, 'card'), ['national_id' => 'A123456', 'full_name' => 'ALI, AMINATH', 'gender' => 'female', 'date_of_birth' => '1986-03-03']);
        $traveller = $acceptor->accept($card, $card->fields);

        $mismatches = $traveller->unexplainedMismatches();
        $this->assertCount(1, $mismatches, 'The names agree as sets of words; the dates do not.');
        $this->assertSame('date_of_birth', $mismatches[0]['field']);
        $this->assertSame('1986-03-03', $mismatches[0]['id_card']);
        $this->assertSame('1986-03-02', $mismatches[0]['passport']);
        $this->assertSame('A123456', $traveller->national_id);

        app(IdentityCheck::class)->explain($traveller, 'date_of_birth', 'The card is misprinted; the passport is right.');
        $this->assertSame([], $traveller->fresh()->unexplainedMismatches());
        $this->assertSame('The card is misprinted; the passport is right.', $traveller->fresh()->identity_mismatches[0]['note']);

        // Re-running keeps the explanation for the same disagreement.
        app(IdentityCheck::class)->run($traveller->fresh());
        $this->assertSame([], $traveller->fresh()->unexplainedMismatches());

        $this->assertSame(1.0, IdentityCheck::nameSimilarity('MOHAMED AHMED', 'AHMED, MOHAMED'));
        $this->assertLessThan(0.6, IdentityCheck::nameSimilarity('AMINATH ALI', 'FATHIMATH HASSAN'));
    }

    public function test_duplicates_are_found_by_id_number_passport_or_name_and_birthday(): void
    {
        $customer = Customer::factory()->create(['name' => 'Hassan Ibrahim']);
        $existing = Traveller::factory()->for($customer)->create(['full_name' => 'Aminath Ali', 'national_id' => 'A123456', 'passport_number' => 'P1234567', 'date_of_birth' => '1986-03-02']);
        $other = Traveller::factory()->for($customer)->create(['full_name' => 'Ibrahim Didi', 'passport_number' => 'Q7654321']);

        $this->assertTrue(Duplicates::find('a123456', null, null, null)->contains($existing));
        $this->assertTrue(Duplicates::find(null, 'P1234567', null, null)->contains($existing));
        $this->assertTrue(Duplicates::find(null, null, 'AMINATH ALI', '1986-03-02')->contains($existing));
        $this->assertFalse(Duplicates::find(null, null, 'Aminath', '1990-01-01')->contains($existing), 'A name alone is not a match.');
        $this->assertTrue(Duplicates::find(null, null, null, null)->isEmpty());
        $this->assertFalse(Duplicates::find('A123456', null, null, null, $existing->getKey())->contains($existing), 'Not a duplicate of itself.');

        $warning = Duplicates::warningFor($other, ['passport_number' => 'P1234567']);
        $this->assertStringContainsString('Aminath Ali (booked by Hassan Ibrahim)', (string) $warning);
        $this->assertNull(Duplicates::warningFor($other, ['passport_number' => 'Q7654321']));
    }

    public function test_a_visa_copy_accepted_marks_the_open_application_issued_with_the_copy_as_evidence(): void
    {
        $this->actingAs($this->staff(Access::VISA_STAFF));
        $departure = Departure::factory()->withSeats(20)->create(['date_start' => now()->addDays(30), 'date_end' => now()->addDays(44)]);
        $traveller = $this->traveller();
        $booking = Booking::factory()->create(['customer_id' => $traveller->customer_id, 'departure_id' => $departure->getKey(), 'seats' => 1]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();
        $booking->travellers()->create(['traveller_id' => $traveller->getKey(), 'occupancy' => 'double', 'is_lead' => true]);
        $application = app(VisaDesk::class)->open($booking, $traveller);
        $this->assertSame(VisaApplication::NOT_STARTED, $application->status);

        $version = $this->version($traveller, Document::VISA, 'e-visa');
        $reading = $this->proposed($version, ['number' => '123456789', 'full_name' => 'AMINATH ALI', 'passport_number' => 'P1234567', 'issued_on' => '2026-11-01', 'expires_on' => '2026-12-30']);

        app(Acceptor::class)->accept($reading, $reading->fields, verify: true, issueVisa: true);

        $application->refresh();
        $this->assertSame(VisaApplication::ISSUED, $application->status);
        $this->assertSame('123456789', $application->reference);
        $this->assertSame('2026-12-30', $application->expires_at->toDateString());
        $this->assertNotNull($application->issued_at);
        $this->assertSame($version->document_id, $application->events()->where('to_status', VisaApplication::ISSUED)->first()?->document_id, 'The copy is the evidence.');
        $this->assertSame('2026-12-30', $version->document->fresh()->expires_at->toDateString());
        $this->assertSame(Document::VERIFIED, $version->document->fresh()->status);
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    public static function png(int $width, int $height, int $grey = 255): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $grey, $grey, $grey));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** A white page with the two MRZ lines printed in a monospace face along the bottom, as a scanner would see them. */
    public static function passportImage(string $first, string $second): string
    {
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSansMono.ttf');
        $image = imagecreatetruecolor(1600, 1100);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        $ink = (int) imagecolorallocate($image, 0, 0, 0);
        imagettftext($image, 30, 0, 80, 300, $ink, $font, 'REPUBLIC OF MALDIVES  PASSPORT');
        imagettftext($image, 34, 0, 60, 900, $ink, $font, $first);
        imagettftext($image, 34, 0, 60, 980, $ink, $font, $second);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
