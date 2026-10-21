<?php

namespace App\Services\Documents\Reading\Readers;

use App\Models\Document;
use App\Services\Documents\Reading\Extraction;
use App\Services\Documents\Reading\FieldParser;
use App\Services\Documents\Reading\ImagePrep;
use App\Services\Documents\Reading\PdfText;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Tesseract as a binary on the server: no image leaves the host. A PDF's
 * text layer is used as it is; an image is prepared with GD and read, with
 * a second pass over the bottom of a passport for the machine-readable
 * zone, where a whitelist of the characters it can hold wins over the
 * same lines smudged in the full-page pass.
 */
final class TesseractReader implements Reader
{
    public function name(): string
    {
        return 'tesseract';
    }

    public function isAvailable(): bool
    {
        $binary = (string) config('documents.tesseract.binary', 'tesseract');

        if (str_contains($binary, '/')) {
            return is_executable($binary);
        }

        $probe = new Process(['which', $binary]);
        $probe->run();

        return $probe->isSuccessful();
    }

    public function read(string $bytes, string $mime, string $type): Extraction
    {
        $x = new Extraction($this->name(), model: 'tesseract');

        if ($mime === 'application/pdf') {
            $x->rawText = PdfText::all($bytes);
        } else {
            $text = $this->ocr(ImagePrep::forOcr($bytes));

            if ($type === Document::PASSPORT) {
                $text = $this->ocr(ImagePrep::forOcr(ImagePrep::bottom($bytes, 0.35)), mrz: true)."\n".$text;
            }

            $x->rawText = $text;
        }

        FieldParser::apply($x, $type);

        return $x;
    }

    private function ocr(string $png, bool $mrz = false): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'ocr');

        if ($temporary === false) {
            throw new RuntimeException('Could not write a temporary file for OCR.');
        }

        $path = $temporary.'.png';
        file_put_contents($path, $png);

        try {
            $command = [
                (string) config('documents.tesseract.binary', 'tesseract'),
                $path, 'stdout',
                '-l', (string) config('documents.tesseract.languages', 'eng'),
                '--psm', $mrz ? '6' : '4',
            ];

            if ($mrz) {
                $command[] = '-c';
                $command[] = 'tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<';
            }

            $env = [];
            $prefix = config('documents.tesseract.tessdata');

            if (filled($prefix)) {
                $env['TESSDATA_PREFIX'] = (string) $prefix;
            }

            $process = new Process($command, null, $env === [] ? null : $env, null, (float) config('documents.tesseract.timeout', 60));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('tesseract failed: '.trim($process->getErrorOutput()));
            }

            return $process->getOutput();
        } finally {
            @unlink($path);
            @unlink($temporary);
        }
    }
}
