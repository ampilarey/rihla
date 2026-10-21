<?php

namespace App\Services\Documents\Reading;

use Symfony\Component\Process\Process;

/**
 * The text layer of a PDF, per page, through poppler's pdftotext. E-visas
 * from the Saudi system and bank statements are generated PDFs with real
 * text, so no OCR is needed for them — when the host has the binary. A
 * host without it gets an empty answer, and the reader goes on without.
 */
final class PdfText
{
    public static function isAvailable(): bool
    {
        $binary = (string) config('documents.pdftotext.binary', 'pdftotext');

        if (str_contains($binary, '/')) {
            return is_executable($binary);
        }

        $probe = new Process(['which', $binary]);
        $probe->run();

        return $probe->isSuccessful();
    }

    /** @return list<string>  one string per page; empty when the text cannot be read */
    public static function pages(string $bytes): array
    {
        if (! self::isAvailable()) {
            return [];
        }

        $temporary = tempnam(sys_get_temp_dir(), 'pdf');

        if ($temporary === false) {
            return [];
        }

        file_put_contents($temporary, $bytes);

        try {
            $process = new Process([(string) config('documents.pdftotext.binary', 'pdftotext'), '-layout', '-enc', 'UTF-8', $temporary, '-'], null, null, null, 30.0);
            $process->run();

            if (! $process->isSuccessful()) {
                return [];
            }

            $pages = array_map('trim', explode("\f", $process->getOutput()));

            // pdftotext ends the last page with a form feed, which reads as an empty page after it.
            while ($pages !== [] && end($pages) === '') {
                array_pop($pages);
            }

            return $pages;
        } finally {
            @unlink($temporary);
        }
    }

    public static function all(string $bytes): string
    {
        return implode("\n\n", self::pages($bytes));
    }
}
