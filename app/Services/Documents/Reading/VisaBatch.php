<?php

namespace App\Services\Documents\Reading;

use App\Exceptions\NotAnUmrahBooking;
use App\Models\BookingTraveller;
use App\Models\Departure;
use App\Models\Document;
use App\Models\VisaApplication;
use App\Services\Documents\DocumentWallet;
use App\Services\Operations\Lists;
use App\Services\Visa\VisaDesk;
use App\Support\EncryptedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * A departure's visas, arriving as one PDF with a page per pilgrim or as
 * a folder of files, filed in one go — Hijaz's bulk visa upload.
 *
 * Three steps, with a person between the second and the third. **Split:**
 * every page becomes a file of its own and is read for its visa number,
 * passport number and name. **Match:** each page is offered to the
 * traveller on the departure whose passport number it carries, or failing
 * that the one whose name it nearly is. **File:** for every page the
 * person has left matched, the page goes into that traveller's wallet as
 * their visa, the reading is accepted, and the traveller's visa
 * application on this departure is issued with the document as evidence.
 * A page nobody matched files nothing.
 *
 * The batch sits on the documents disk under its own token, encrypted like
 * everything else there, until it is filed or discarded; whatever is left
 * after a day is swept when the page next opens.
 */
final class VisaBatch
{
    public const DIRECTORY = 'bulk-visas';

    /** How alike two names must be for a page to be matched on the name alone. */
    public const NAME_THRESHOLD = 0.8;

    public const BY_PASSPORT = 'passport';

    public const BY_NAME = 'name';

    public function __construct(
        private readonly ReaderManager $readers,
        private readonly DocumentWallet $wallet,
        private readonly Acceptor $acceptor,
        private readonly VisaDesk $desk,
        private readonly Lists $lists,
    ) {}

    /** Whether a PDF can be taken apart on this host: poppler's pdftotext and pdfseparate. */
    public static function canSplit(): bool
    {
        return PdfText::isAvailable() && self::binaryExists((string) config('documents.pdfseparate.binary', 'pdfseparate'));
    }

    public static function newBatch(): string
    {
        return Str::random(32);
    }

    /**
     * One upload — a PDF of many pages or a single image — into pages of
     * the batch, each read.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException when the file cannot be taken apart or read
     */
    public function split(string $batch, UploadedFile $file): array
    {
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $name = $file->getClientOriginalName() ?: 'upload';
        $bytes = (string) file_get_contents((string) $file->getRealPath());

        if ($mime !== 'application/pdf') {
            $extraction = $this->readers->run($this->readers->reader(), $bytes, $mime, Document::VISA);

            return [$this->page($batch, $name, 1, 1, $bytes, $mime, $extraction)];
        }

        if (! self::canSplit()) {
            throw new RuntimeException('This host cannot take a PDF apart (poppler is not installed). Upload the visas as images, or one PDF per pilgrim through the traveller\'s documents.');
        }

        $texts = PdfText::pages($bytes);

        if ($texts === []) {
            throw new RuntimeException($name.' could not be read as a PDF.');
        }

        $parts = $this->separate((string) $file->getRealPath(), count($texts));
        $pages = [];

        foreach ($parts as $index => $part) {
            $number = $index + 1;
            $text = $texts[$index] ?? '';

            if (trim($text) !== '') {
                $extraction = new Extraction('pdf-text', rawText: $text);
                FieldParser::apply($extraction, Document::VISA);
            } elseif ($this->readers->proposes()) {
                // A scanned page has no text layer: rendered and read like a photo.
                $rendered = $this->render((string) $file->getRealPath(), $number);
                $extraction = $rendered === null
                    ? new Extraction('manual')
                    : $this->readers->run($this->readers->reader(), $rendered, 'image/png', Document::VISA);
            } else {
                $extraction = new Extraction('manual');
            }

            $pages[] = $this->page($batch, $name, $number, count($parts), $part, 'application/pdf', $extraction);
        }

        return $pages;
    }

    /**
     * Each page offered to a traveller on the departure: by passport
     * number when the page carries one that exactly one traveller has,
     * else by a name alike enough and unlike every other. Anything less
     * is left for the person, with nothing chosen.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    public function match(Departure $departure, array $pages): array
    {
        $members = $this->members($departure);

        foreach ($pages as &$page) {
            $page['traveller_id'] = null;
            $page['match'] = null;

            $passport = self::passport((string) ($page['fields']['passport_number'] ?? ''));

            if ($passport !== '') {
                $byPassport = $members->filter(fn (BookingTraveller $member): bool => self::passport((string) $member->traveller->passport_number) === $passport);

                if ($byPassport->count() === 1) {
                    $page['traveller_id'] = (int) $byPassport->first()->traveller_id;
                    $page['match'] = self::BY_PASSPORT;

                    continue;
                }
            }

            $name = (string) ($page['fields']['full_name'] ?? '');

            if ($name === '') {
                continue;
            }

            $scored = $members
                ->map(fn (BookingTraveller $member): array => [(int) $member->traveller_id, IdentityCheck::nameSimilarity($name, (string) $member->traveller->full_name)])
                ->sortByDesc(1)
                ->values();

            $best = $scored->get(0);
            $next = $scored->get(1);

            if ($best !== null && $best[1] >= self::NAME_THRESHOLD && ($next === null || $next[1] < $best[1])) {
                $page['traveller_id'] = $best[0];
                $page['match'] = self::BY_NAME;
            }
        }

        return $pages;
    }

    /**
     * Who may be chosen for a page: the departure's travellers, with what
     * the record knows them by.
     *
     * @return array<int, string>
     */
    public function candidates(Departure $departure): array
    {
        return $this->members($departure)
            ->mapWithKeys(fn (BookingTraveller $member): array => [
                (int) $member->traveller_id => $member->traveller->full_name
                    .(filled($member->traveller->passport_number) ? ' · '.$member->traveller->passport_number : '')
                    .' (booked by '.$member->booking->customer->name.')',
            ])
            ->all();
    }

    /**
     * File every page with a traveller chosen: the page into the wallet as
     * that traveller's visa, the reading accepted, and the application on
     * this departure issued with the document as evidence. A page that
     * fails is reported and does not stop the rest. The batch is gone
     * afterwards, whatever happened.
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array{filed: int, left: int, notes: list<string>}
     */
    public function file(Departure $departure, array $pages, string $batch): array
    {
        $members = $this->members($departure)->keyBy('traveller_id');
        $filed = 0;
        $left = 0;
        $notes = [];

        foreach ($pages as $page) {
            $travellerId = (int) ($page['traveller_id'] ?? 0);
            $label = (string) ($page['file'] ?? 'page').' p'.(int) ($page['page'] ?? 1);

            if ($travellerId < 1) {
                $left++;

                continue;
            }

            $member = $members->get($travellerId);

            if (! $member instanceof BookingTraveller) {
                $left++;
                $notes[] = $label.': that traveller is not on this departure.';

                continue;
            }

            $path = (string) ($page['path'] ?? '');

            if (! str_starts_with($path, self::DIRECTORY.'/'.$batch.'/') || ($bytes = EncryptedFile::contents($this->disk(), $path)) === null) {
                $left++;
                $notes[] = $label.': the file is no longer there. Upload it again.';

                continue;
            }

            try {
                $this->fileOne($member, $page, $bytes);
                $filed++;
            } catch (Throwable $e) {
                Log::warning('Bulk visa filing failed', ['page' => $label, 'traveller_id' => $travellerId, 'error' => $e->getMessage()]);
                $left++;
                $notes[] = $label.' for '.$member->traveller->full_name.': '.$e->getMessage();
            }
        }

        $this->discard($batch);

        return ['filed' => $filed, 'left' => $left, 'notes' => $notes];
    }

    /** Throw the batch away, filed or not. */
    public function discard(string $batch): void
    {
        if ($batch !== '') {
            Storage::disk($this->disk())->deleteDirectory(self::DIRECTORY.'/'.$batch);
        }
    }

    /** Batches nobody came back to: anything older than a day goes. */
    public function sweep(): int
    {
        $disk = Storage::disk($this->disk());
        $swept = 0;

        foreach ($disk->directories(self::DIRECTORY) as $directory) {
            $newest = collect($disk->files($directory))->map(fn (string $file): int => $disk->lastModified($file))->max();

            if ($newest === null || $newest < now()->subDay()->getTimestamp()) {
                $disk->deleteDirectory($directory);
                $swept++;
            }
        }

        return $swept;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $page */
    private function fileOne(BookingTraveller $member, array $page, string $bytes): void
    {
        $traveller = $member->traveller;
        $booking = $member->booking;
        $temporary = tempnam(sys_get_temp_dir(), 'visa');

        if ($temporary === false) {
            throw new RuntimeException('Could not write a temporary file.');
        }

        try {
            file_put_contents($temporary, $bytes);
            $mime = (string) ($page['mime'] ?? 'application/pdf');
            $original = (string) ($page['file'] ?? 'visa');
            $extension = pathinfo($original, PATHINFO_EXTENSION) ?: ($mime === 'application/pdf' ? 'pdf' : (explode('/', $mime)[1] ?? 'bin'));
            $base = pathinfo($original, PATHINFO_FILENAME) ?: 'visa';
            $name = ((int) ($page['of'] ?? 1)) > 1 ? $base.'-p'.(int) ($page['page'] ?? 1).'.'.$extension : $base.'.'.$extension;

            $upload = new UploadedFile($temporary, $name, $mime, null, true);

            // Read already; the wallet is told not to read it again.
            $version = $this->wallet->store($traveller, $upload, Document::TRAVEL, Document::VISA, read: false);
        } finally {
            @unlink($temporary);
        }

        /** @var array<string, string> $fields */
        $fields = is_array($page['fields'] ?? null) ? $page['fields'] : [];
        /** @var array<string, float> $confidence */
        $confidence = is_array($page['confidence'] ?? null) ? $page['confidence'] : [];

        $extraction = $this->readers->record(
            $version,
            new Extraction((string) ($page['reader'] ?? 'manual'), $fields, $confidence, isset($page['raw_text']) ? (string) $page['raw_text'] : null),
            notify: false,
        );

        // A visa never renames anybody: the match was the person's decision,
        // and the name on the page was only ever a way of finding them.
        unset($fields['full_name']);

        $this->acceptor->accept($extraction, $fields, verify: true);

        $existing = VisaApplication::forBooking($booking)
            ->where('traveller_id', $traveller->getKey())
            ->orderByDesc('attempt')
            ->first();

        if ($existing instanceof VisaApplication && $existing->status === VisaApplication::ISSUED) {
            // A second copy of a visa already issued: filed, and the
            // application left as it was.
            return;
        }

        try {
            $application = $this->desk->open($booking, $traveller);
        } catch (NotAnUmrahBooking) {
            return;
        }

        $this->acceptor->issue($application, $version->document, $fields);
    }

    /** @return array<string, mixed> */
    private function page(string $batch, string $file, int $number, int $of, string $bytes, string $mime, Extraction $x): array
    {
        $extension = $mime === 'application/pdf' ? 'pdf' : (explode('/', $mime)[1] ?? 'bin');
        $path = self::DIRECTORY.'/'.$batch.'/'.$number.'-'.Str::random(12).'.'.$extension;

        if (! EncryptedFile::put($this->disk(), $path, $bytes)) {
            throw new RuntimeException('The page could not be kept on the documents disk.');
        }

        return [
            'file' => $file,
            'page' => $number,
            'of' => $of,
            'path' => $path,
            'mime' => $mime,
            'reader' => $x->reader,
            'fields' => $x->fields,
            'confidence' => $x->confidence,
            'raw_text' => $x->rawText === null ? null : mb_substr($x->rawText, 0, 4000),
            'traveller_id' => null,
            'match' => null,
        ];
    }

    /**
     * Every page of the PDF as a PDF of its own, in order.
     *
     * @return list<string>
     */
    private function separate(string $path, int $expected): array
    {
        $directory = sys_get_temp_dir().'/visabatch-'.Str::random(12);

        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Could not make a temporary directory.');
        }

        try {
            $process = new Process([(string) config('documents.pdfseparate.binary', 'pdfseparate'), $path, $directory.'/p-%d.pdf'], null, null, null, 120.0);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('The PDF could not be taken apart: '.trim($process->getErrorOutput()));
            }

            $parts = [];

            for ($number = 1; $number <= $expected; $number++) {
                $part = $directory.'/p-'.$number.'.pdf';

                if (! is_file($part)) {
                    break;
                }

                $parts[] = (string) file_get_contents($part);
            }

            if ($parts === []) {
                throw new RuntimeException('The PDF came apart into no pages.');
            }

            return $parts;
        } finally {
            foreach (glob($directory.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($directory);
        }
    }

    /** One page as a PNG, for a reader; null when the host cannot render it. */
    private function render(string $path, int $number): ?string
    {
        $binary = (string) config('documents.pdftoppm.binary', 'pdftoppm');

        if (! self::binaryExists($binary)) {
            return null;
        }

        $prefix = sys_get_temp_dir().'/visapage-'.Str::random(12);

        try {
            $process = new Process([$binary, '-r', '150', '-png', '-f', (string) $number, '-l', (string) $number, '-singlefile', $path, $prefix], null, null, null, 60.0);
            $process->run();

            return $process->isSuccessful() && is_file($prefix.'.png') ? (string) file_get_contents($prefix.'.png') : null;
        } finally {
            @unlink($prefix.'.png');
        }
    }

    /** @return Collection<int, BookingTraveller> */
    private function members(Departure $departure): Collection
    {
        return $this->lists->travellers($departure);
    }

    private function disk(): string
    {
        return (string) config('documents.disk', 'documents');
    }

    private static function passport(string $number): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));
    }

    private static function binaryExists(string $binary): bool
    {
        if (str_contains($binary, '/')) {
            return is_executable($binary);
        }

        $probe = new Process(['which', $binary]);
        $probe->run();

        return $probe->isSuccessful();
    }
}
