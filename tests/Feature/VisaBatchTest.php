<?php

namespace Tests\Feature;

use App\Filament\Pages\VisaBatch as VisaBatchPage;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Traveller;
use App\Models\User;
use App\Models\VisaApplication;
use App\Services\Documents\Reading\VisaBatch;
use App\Services\Telegram\Linker;
use App\Services\Visa\VisaDesk;
use App\Support\Access;
use App\Support\EncryptedFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Bulk visa upload — batch B part 2 from Hijaz. A PDF with a page per
 * pilgrim is split, read, matched to the departure's travellers and, once
 * a person has looked, filed into each wallet with the application
 * issued. The tests that need poppler skip without it; CI installs it.
 */
class VisaBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    /**
     * A departure with a confirmed booking per person.
     *
     * @param  list<array{name: string, passport?: ?string}>  $people
     * @return array{Departure, array<string, Traveller>}
     */
    private function departure(array $people): array
    {
        $departure = Departure::factory()->withSeats(20)->create(['date_start' => now()->addDays(30), 'date_end' => now()->addDays(44)]);
        $travellers = [];

        foreach ($people as $index => $person) {
            $customer = Customer::factory()->create(['name' => $person['name']]);
            $traveller = Traveller::factory()->for($customer)->create(['full_name' => $person['name'], 'passport_number' => $person['passport'] ?? null]);
            $booking = Booking::factory()->create(['customer_id' => $customer->getKey(), 'departure_id' => $departure->getKey(), 'seats' => 1]);
            $booking->forceFill(['status' => Booking::CONFIRMED])->save();
            $booking->travellers()->create(['traveller_id' => $traveller->getKey(), 'occupancy' => 'double', 'is_lead' => true]);
            $travellers[$person['name'].($index > 0 && isset($travellers[$person['name']]) ? ' #'.($index + 1) : '')] = $traveller;
        }

        return [$departure, $travellers];
    }

    /** @param  list<array{number: string, passport: string, name: string, until: string}>  $pages */
    private function pdf(array $pages, string $name = 'visas.pdf'): UploadedFile
    {
        $html = implode('<div style="page-break-after: always;"></div>', array_map(
            fn (array $page): string => '<p>KINGDOM OF SAUDI ARABIA</p><p>VISA NO: '.$page['number'].'</p><p>PASSPORT NO: '.$page['passport'].'</p><p>NAME: '.$page['name'].'</p><p>VALID UNTIL: '.$page['until'].'</p>',
            $pages,
        ));

        $path = (string) tempnam(sys_get_temp_dir(), 'visas');
        file_put_contents($path, Pdf::loadHTML($html)->output());

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function needsPoppler(): void
    {
        if (! VisaBatch::canSplit()) {
            $this->markTestSkipped('poppler is not on this host.');
        }
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    /** @return list<array<string, mixed>> */
    private function readAndMatch(Departure $departure, string $batch, UploadedFile $file): array
    {
        $service = app(VisaBatch::class);

        return $service->match($departure, $service->split($batch, $file));
    }

    public function test_a_pdf_with_a_page_per_pilgrim_is_taken_apart_read_and_offered_to_the_right_people(): void
    {
        $this->needsPoppler();

        [$departure, $t] = $this->departure([
            ['name' => 'Aminath Ali', 'passport' => 'P1234567'],
            ['name' => 'Hassan Ibrahim'],
            ['name' => 'Mariyam Didi', 'passport' => 'P7654321'],
            ['name' => 'Mariyam Didi', 'passport' => 'P1111111'],
        ]);

        $batch = VisaBatch::newBatch();
        $pages = app(VisaBatch::class)->split($batch, $this->pdf([
            ['number' => '111111111', 'passport' => 'P1234567', 'name' => 'AMINATH ALI', 'until' => '30/12/2026'],
            ['number' => '222222222', 'passport' => 'P9999999', 'name' => 'HASSAN IBRAHIM', 'until' => '30/12/2026'],
            ['number' => '333333333', 'passport' => 'P0000000', 'name' => 'MARIYAM DIDI', 'until' => '30/12/2026'],
            ['number' => '444444444', 'passport' => 'P5555555', 'name' => 'SOMEONE ELSE', 'until' => '30/12/2026'],
        ]));

        $this->assertCount(4, $pages);
        $this->assertSame([1, 2, 3, 4], array_column($pages, 'page'));
        $this->assertSame('pdf-text', $pages[0]['reader']);
        $this->assertSame('111111111', $pages[0]['fields']['number']);
        $this->assertSame('P1234567', $pages[0]['fields']['passport_number']);
        $this->assertSame('2026-12-30', $pages[0]['fields']['expires_on']);
        $this->assertSame('444444444', $pages[3]['fields']['number']);

        // Each page is its own file on the documents disk, encrypted like the wallet's.
        foreach ($pages as $page) {
            $this->assertStringStartsWith(VisaBatch::DIRECTORY.'/'.$batch.'/', $page['path']);
            $this->assertTrue(EncryptedFile::looksEncrypted((string) Storage::disk('documents')->get($page['path'])));
            $this->assertStringStartsWith('%PDF', (string) EncryptedFile::contents('documents', $page['path']));
        }

        $pages = app(VisaBatch::class)->match($departure, $pages);

        $this->assertSame($t['Aminath Ali']->getKey(), $pages[0]['traveller_id'], 'By passport number.');
        $this->assertSame(VisaBatch::BY_PASSPORT, $pages[0]['match']);
        $this->assertSame($t['Hassan Ibrahim']->getKey(), $pages[1]['traveller_id'], 'No passport on file: by name.');
        $this->assertSame(VisaBatch::BY_NAME, $pages[1]['match']);
        $this->assertNull($pages[2]['traveller_id'], 'Two travellers of that name and neither passport: nobody is guessed.');
        $this->assertNull($pages[3]['traveller_id']);

        $candidates = app(VisaBatch::class)->candidates($departure);
        $this->assertCount(4, $candidates);
        $this->assertSame('Aminath Ali · P1234567 (booked by Aminath Ali)', $candidates[$t['Aminath Ali']->getKey()]);
    }

    public function test_filing_puts_each_page_in_its_wallet_as_a_verified_visa_and_issues_the_application(): void
    {
        $this->needsPoppler();
        $this->actingAs($visa = $this->staff(Access::VISA_STAFF));

        [$departure, $t] = $this->departure([
            ['name' => 'Aminath Ali', 'passport' => 'P1234567'],
            ['name' => 'Hassan Ibrahim'],
            ['name' => 'Mariyam Didi', 'passport' => 'P7654321'],
        ]);
        $aminath = $t['Aminath Ali'];
        $hassan = $t['Hassan Ibrahim'];
        $mariyam = $t['Mariyam Didi'];

        // Aminath's application is already under way; Hassan has none yet.
        $application = app(VisaDesk::class)->open($aminath->bookingTravellers()->first()->booking, $aminath);
        $application->transitionTo(VisaApplication::PREPARING, 'Papers in.');

        $batch = VisaBatch::newBatch();
        $pages = $this->readAndMatch($departure, $batch, $this->pdf([
            ['number' => '111111111', 'passport' => 'P1234567', 'name' => 'AMINATH ALI', 'until' => '30/12/2026'],
            ['number' => '222222222', 'passport' => 'P9999999', 'name' => 'HASSAN IBRAHIM', 'until' => '30/12/2026'],
            ['number' => '333333333', 'passport' => 'P0000000', 'name' => 'NOBODY HERE', 'until' => '30/12/2026'],
            ['number' => '444444444', 'passport' => 'P5555555', 'name' => 'SOMEONE ELSE', 'until' => '30/12/2026'],
        ]));

        // The person matches page three by hand and leaves page four out.
        $pages[2]['traveller_id'] = $mariyam->getKey();

        $result = app(VisaBatch::class)->file($departure, $pages, $batch);

        $this->assertSame(3, $result['filed']);
        $this->assertSame(1, $result['left']);
        $this->assertSame([], $result['notes']);

        foreach ([$aminath, $hassan, $mariyam] as $traveller) {
            $document = Document::query()->where('traveller_id', $traveller->getKey())->where('type', Document::VISA)->sole();
            $this->assertSame(Document::TRAVEL, $document->category);
            $this->assertSame(Document::VERIFIED, $document->status);
            $this->assertSame('2026-12-30', $document->expires_at?->toDateString());
            $this->assertSame(DocumentExtraction::ACCEPTED, $document->latestReading()?->status);
            $this->assertSame($visa->getKey(), $document->latestReading()?->reviewed_by);

            $issued = VisaApplication::query()->where('traveller_id', $traveller->getKey())->sole();
            $this->assertSame(VisaApplication::ISSUED, $issued->status);
            $this->assertSame('2026-12-30', $issued->expires_at?->toDateString());
            $this->assertSame($document->getKey(), $issued->events()->where('to_status', VisaApplication::ISSUED)->sole()->document_id, 'The copy is the evidence.');
        }

        $this->assertSame('111111111', VisaApplication::query()->where('traveller_id', $aminath->getKey())->sole()->reference);
        $this->assertSame('visas-p1.pdf', Document::query()->where('traveller_id', $aminath->getKey())->sole()->currentVersion()?->original_filename);

        // A visa names nobody: the match was the person's decision, and
        // Hassan's record still has no passport number from a page that
        // carried the wrong one.
        $this->assertNull($hassan->fresh()->passport_number);
        $this->assertSame('P1234567', $aminath->fresh()->passport_number);

        // The batch is gone, filed or not; the pages live on only in the wallets.
        $this->assertSame([], Storage::disk('documents')->allFiles(VisaBatch::DIRECTORY));
        $this->assertDatabaseMissing('documents', ['traveller_id' => $mariyam->getKey(), 'type' => Document::PASSPORT]);
    }

    public function test_a_photo_is_one_page_and_can_be_filed_by_hand_when_nothing_reads(): void
    {
        config(['documents.reader' => 'manual']);
        $this->actingAs($this->staff(Access::VISA_STAFF));
        [$departure, $t] = $this->departure([['name' => 'Aminath Ali', 'passport' => 'P1234567']]);
        $aminath = $t['Aminath Ali'];

        $batch = VisaBatch::newBatch();
        $pages = $this->readAndMatch($departure, $batch, UploadedFile::fake()->image('visa.jpg', 800, 1100));

        $this->assertCount(1, $pages);
        $this->assertSame('image/jpeg', $pages[0]['mime']);
        $this->assertSame([], $pages[0]['fields']);
        $this->assertNull($pages[0]['traveller_id'], 'Nothing read, nobody guessed.');

        $pages[0]['traveller_id'] = $aminath->getKey();
        $result = app(VisaBatch::class)->file($departure, $pages, $batch);

        $this->assertSame(['filed' => 1, 'left' => 0, 'notes' => []], $result);

        $document = Document::query()->where('traveller_id', $aminath->getKey())->where('type', Document::VISA)->sole();
        $this->assertSame('visa.jpg', $document->currentVersion()?->original_filename);
        $this->assertSame(Document::VERIFIED, $document->status);
        $this->assertNull($document->expires_at);

        // No application existed: the desk opened one and it is issued on the copy.
        $issued = VisaApplication::query()->where('traveller_id', $aminath->getKey())->sole();
        $this->assertSame(VisaApplication::ISSUED, $issued->status);
        $this->assertNull($issued->reference);
    }

    public function test_a_second_copy_of_a_visa_already_issued_is_filed_and_the_application_left_alone(): void
    {
        $this->actingAs($this->staff(Access::VISA_STAFF));
        [$departure, $t] = $this->departure([['name' => 'Aminath Ali', 'passport' => 'P1234567']]);
        $aminath = $t['Aminath Ali'];

        $application = app(VisaDesk::class)->open($aminath->bookingTravellers()->first()->booking, $aminath);
        foreach ([VisaApplication::PREPARING, VisaApplication::SUBMITTED, VisaApplication::ISSUED] as $status) {
            $application->transitionTo($status);
        }

        $batch = VisaBatch::newBatch();
        $pages = $this->readAndMatch($departure, $batch, UploadedFile::fake()->image('visa-again.png', 800, 1100));
        $pages[0]['traveller_id'] = $aminath->getKey();

        $this->assertSame(1, app(VisaBatch::class)->file($departure, $pages, $batch)['filed']);
        $this->assertSame(1, VisaApplication::query()->where('traveller_id', $aminath->getKey())->count(), 'No second attempt was opened.');
        $this->assertSame(1, $application->fresh()->attempt);
        $this->assertSame(VisaApplication::ISSUED, $application->fresh()->status);
        $this->assertTrue(Document::query()->where('traveller_id', $aminath->getKey())->where('type', Document::VISA)->exists());
    }

    public function test_a_traveller_off_the_departure_a_missing_file_and_a_bad_page_are_reported_and_the_rest_filed(): void
    {
        $this->actingAs($this->staff(Access::VISA_STAFF));
        [$departure, $t] = $this->departure([['name' => 'Aminath Ali', 'passport' => 'P1234567']]);
        $stranger = Traveller::factory()->for(Customer::factory()->create())->create(['full_name' => 'Not Travelling']);

        $batch = VisaBatch::newBatch();
        $pages = $this->readAndMatch($departure, $batch, UploadedFile::fake()->image('one.jpg', 800, 1100));
        $pages[] = ['file' => 'two.jpg', 'page' => 1, 'of' => 1, 'path' => VisaBatch::DIRECTORY.'/'.$batch.'/gone.jpg', 'mime' => 'image/jpeg', 'reader' => 'manual', 'fields' => [], 'confidence' => [], 'traveller_id' => $t['Aminath Ali']->getKey()];
        $pages[] = ['file' => 'three.jpg', 'page' => 1, 'of' => 1, 'path' => 'somewhere/else.jpg', 'mime' => 'image/jpeg', 'reader' => 'manual', 'fields' => [], 'confidence' => [], 'traveller_id' => $t['Aminath Ali']->getKey()];
        $pages[0]['traveller_id'] = $stranger->getKey();

        $result = app(VisaBatch::class)->file($departure, $pages, $batch);

        $this->assertSame(0, $result['filed']);
        $this->assertSame(3, $result['left']);
        $this->assertSame([
            'one.jpg p1: that traveller is not on this departure.',
            'two.jpg p1: the file is no longer there. Upload it again.',
            'three.jpg p1: the file is no longer there. Upload it again.',
        ], $result['notes']);
        $this->assertFalse(Document::query()->where('type', Document::VISA)->exists());
    }

    public function test_a_pdf_is_refused_when_the_host_cannot_take_it_apart(): void
    {
        config(['documents.pdfseparate.binary' => '/nonexistent/pdfseparate']);
        $this->assertFalse(VisaBatch::canSplit());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('poppler is not installed');

        app(VisaBatch::class)->split(VisaBatch::newBatch(), $this->pdf([['number' => '1', 'passport' => 'P1', 'name' => 'A B', 'until' => '30/12/2026']]));
    }

    public function test_the_page_is_visa_staff_s_files_what_was_left_matched_and_throws_the_rest_away(): void
    {
        $visa = $this->staff(Access::VISA_STAFF);
        $this->actingAs($visa);
        [$departure, $t] = $this->departure([['name' => 'Aminath Ali', 'passport' => 'P1234567'], ['name' => 'Hassan Ibrahim']]);

        $this->get(VisaBatchPage::getUrl())->assertOk()->assertSee('Visas in bulk');
        $this->actingAs($this->staff(Access::TOUR_LEADER))->get(VisaBatchPage::getUrl())->assertForbidden();
        $this->actingAs($visa);

        $batch = VisaBatch::newBatch();
        $pages = $this->readAndMatch($departure, $batch, UploadedFile::fake()->image('one.jpg', 800, 1100));
        $pages = [...$pages, ...$this->readAndMatch($departure, $batch, UploadedFile::fake()->image('two.jpg', 900, 1200))];
        $pages[0]['traveller_id'] = $t['Aminath Ali']->getKey();

        Livewire::test(VisaBatchPage::class)
            ->set('departureId', $departure->getKey())
            ->set('batch', $batch)
            ->set('pages', $pages)
            ->call('file')
            ->assertNotified('1 visa filed · 1 page left out')
            ->assertSet('pages', [])
            ->assertSet('batch', '');

        $this->assertTrue(Document::query()->where('traveller_id', $t['Aminath Ali']->getKey())->where('type', Document::VISA)->exists());
        $this->assertFalse(Document::query()->where('traveller_id', $t['Hassan Ibrahim']->getKey())->exists());
        $this->assertSame([], Storage::disk('documents')->allFiles(VisaBatch::DIRECTORY));

        // Starting again throws the batch away without filing anything.
        $again = VisaBatch::newBatch();
        $pages = $this->readAndMatch($departure, $again, UploadedFile::fake()->image('three.jpg', 800, 1100));
        $pages[0]['traveller_id'] = $t['Hassan Ibrahim']->getKey();

        Livewire::test(VisaBatchPage::class)
            ->set('departureId', $departure->getKey())
            ->set('batch', $again)
            ->set('pages', $pages)
            ->call('discard')
            ->assertSet('pages', []);

        $this->assertSame([], Storage::disk('documents')->allFiles(VisaBatch::DIRECTORY));
        $this->assertFalse(Document::query()->where('traveller_id', $t['Hassan Ibrahim']->getKey())->exists());

        // Nothing read: the file button says so and writes nothing.
        Livewire::test(VisaBatchPage::class)->call('file')->assertNotified('Nothing read yet');
    }

    public function test_a_batch_nobody_came_back_to_is_swept_after_a_day_and_a_fresh_one_is_not(): void
    {
        $disk = Storage::disk('documents');
        $disk->put(VisaBatch::DIRECTORY.'/old/1.pdf', 'x');
        $disk->put(VisaBatch::DIRECTORY.'/fresh/1.pdf', 'y');
        touch($disk->path(VisaBatch::DIRECTORY.'/old/1.pdf'), now()->subDays(2)->getTimestamp());

        $this->assertSame(1, app(VisaBatch::class)->sweep());
        $this->assertFalse($disk->exists(VisaBatch::DIRECTORY.'/old/1.pdf'));
        $this->assertTrue($disk->exists(VisaBatch::DIRECTORY.'/fresh/1.pdf'));

        // Opening the page sweeps.
        touch($disk->path(VisaBatch::DIRECTORY.'/fresh/1.pdf'), now()->subDays(2)->getTimestamp());
        $this->actingAs($this->staff(Access::VISA_STAFF))->get(VisaBatchPage::getUrl())->assertOk();
        $this->assertFalse($disk->exists(VisaBatch::DIRECTORY.'/fresh/1.pdf'));
    }

    public function test_filing_a_batch_does_not_message_staff_about_each_page(): void
    {
        config(['telegram.bot_token' => '123:abc']);
        Http::fake();
        $visa = $this->staff(Access::VISA_STAFF);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($visa), 4242);
        $this->actingAs($visa);
        Http::fake();

        [$departure, $t] = $this->departure([['name' => 'Aminath Ali', 'passport' => 'P1234567']]);
        $batch = VisaBatch::newBatch();
        $pages = $this->readAndMatch($departure, $batch, UploadedFile::fake()->image('one.jpg', 800, 1100));
        $pages[0]['fields'] = ['number' => '111111111', 'passport_number' => 'P1234567'];
        $pages[0]['confidence'] = ['number' => 0.7, 'passport_number' => 0.7];
        $pages[0]['traveller_id'] = $t['Aminath Ali']->getKey();

        $this->assertSame(1, app(VisaBatch::class)->file($departure, $pages, $batch)['filed']);

        Http::assertNothingSent();
        $this->assertSame(['number' => '111111111', 'passport_number' => 'P1234567'], Document::query()->where('type', Document::VISA)->sole()->latestReading()?->accepted_fields);
    }
}
