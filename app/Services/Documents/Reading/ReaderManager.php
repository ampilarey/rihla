<?php

namespace App\Services\Documents\Reading;

use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\DocumentVersion;
use App\Services\Documents\Reading\Readers\AnthropicVisionReader;
use App\Services\Documents\Reading\Readers\ManualReader;
use App\Services\Documents\Reading\Readers\Reader;
use App\Services\Documents\Reading\Readers\TesseractReader;
use App\Services\Telegram\Notifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Picks the configured reader, runs it, and records what it proposed. A
 * reader that is missing or fails produces an empty proposal with the
 * error written down, never an exception on the upload screen.
 */
class ReaderManager
{
    public function reader(): Reader
    {
        $wanted = (string) config('documents.reader', 'auto');

        $reader = match ($wanted) {
            'anthropic' => new AnthropicVisionReader,
            'tesseract' => new TesseractReader,
            'manual' => new ManualReader,
            default => null,
        };

        if ($reader !== null) {
            return $reader->isAvailable() ? $reader : new ManualReader;
        }

        // Auto: the one that reads best, of the ones that can.
        foreach ([new AnthropicVisionReader, new TesseractReader] as $candidate) {
            if ($candidate->isAvailable()) {
                return $candidate;
            }
        }

        return new ManualReader;
    }

    /** Whether anything would be proposed at all; the upload screens skip the work when not. */
    public function proposes(): bool
    {
        return ! $this->reader() instanceof ManualReader;
    }

    /** Read a stored file and record the proposal against it. */
    public function read(DocumentVersion $version, ?string $typeOverride = null): DocumentExtraction
    {
        $type = $typeOverride ?? (string) $version->document->type;
        $bytes = $version->contents();
        $reader = $this->reader();

        $x = $bytes === null
            ? Extraction::empty($reader->name(), 'The file is missing from disk.')
            : $this->run($reader, $bytes, (string) $version->mime_type, $type);

        return $this->record($version, $x);
    }

    /** Write a proposal against a version. Staff who may verify documents are told, by chat, when there is something to accept. */
    public function record(DocumentVersion $version, Extraction $x, bool $notify = true): DocumentExtraction
    {
        $extraction = $version->extractions()->create([
            'reader' => $x->reader,
            'model' => $x->model,
            'fields' => $x->fields === [] ? null : $x->fields,
            'confidence' => $x->confidence === [] ? null : $x->confidence,
            'raw_text' => $x->rawText === null ? null : mb_substr($x->rawText, 0, 20000),
            'mrz_valid' => $x->mrzValid,
            'status' => DocumentExtraction::PROPOSED,
            'error' => $x->error === null ? null : mb_substr($x->error, 0, 255),
            'created_at' => now(),
        ]);

        // Not for a reading about to be accepted in the same breath (a visa
        // batch): forty "to accept" messages for nothing to accept.
        if ($notify && $extraction->hasProposal()) {
            app(Notifier::class)->readingProposed($extraction);
        }

        return $extraction;
    }

    /**
     * Read bytes without a stored version. A PDF with a text layer (an
     * e-visa, a bank statement) needs no OCR at all, whichever reader is
     * configured.
     */
    public function run(Reader $reader, string $bytes, string $mime, string $type): Extraction
    {
        if ($mime === 'application/pdf' && trim($text = PdfText::all($bytes)) !== '') {
            $x = new Extraction('pdf-text', rawText: $text);
            FieldParser::apply($x, $type);

            return $x;
        }

        try {
            return $reader->read($bytes, $mime, $type);
        } catch (Throwable $e) {
            Log::warning('Document reading failed', ['reader' => $reader->name(), 'error' => $e->getMessage()]);

            return Extraction::empty($reader->name(), $e->getMessage());
        }
    }

    public static function readable(string $type): bool
    {
        return in_array($type, Document::READABLE, true);
    }
}
