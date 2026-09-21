<?php

namespace App\Console\Commands;

use App\Models\DocumentVersion;
use App\Models\Payment;
use App\Support\EncryptedFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Encrypt the documents and slips written before §10.4's encryption at rest.
 *
 * Idempotent: a file already carrying {@see EncryptedFile::MAGIC} is left
 * alone, so running this twice costs a read and nothing else. Safe to run
 * while the site is up, because reads fall back to plaintext for anything
 * not yet converted — there is no window in which a download breaks.
 *
 * Each file is written to a temporary name and moved into place, so an
 * interrupted run leaves either the original or the encrypted copy and
 * never a half-written one. A passport scan truncated by a timeout is the
 * one outcome worth engineering against.
 */
class EncryptStoredFiles extends Command
{
    protected $signature = 'documents:encrypt {--dry-run : Report what would change and change nothing}';

    protected $description = 'Encrypt identity documents and payment slips written before encryption at rest';

    public function handle(): int
    {
        if (! EncryptedFile::enabled()) {
            $this->error('Refusing to run: documents.encrypt_at_rest is off.');
            $this->line('Turn it on first, or this would convert files the application would then write plaintext beside.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $done = 0;
        $already = 0;
        $missing = 0;

        foreach (DocumentVersion::query()->cursor() as $version) {
            [$done, $already, $missing] = $this->convert(
                (string) $version->disk,
                (string) $version->path,
                'document '.$version->getKey(),
                $dry,
                [$done, $already, $missing],
            );
        }

        foreach (Payment::query()->whereNotNull('slip_path')->cursor() as $payment) {
            [$done, $already, $missing] = $this->convert(
                (string) $payment->slip_disk,
                (string) $payment->slip_path,
                'slip for payment '.$payment->getKey(),
                $dry,
                [$done, $already, $missing],
            );
        }

        $this->newLine();
        $this->info(($dry ? 'Would encrypt ' : 'Encrypted ').$done.' file(s). '
            .$already.' already encrypted, '.$missing.' missing from disk.');

        return self::SUCCESS;
    }

    /**
     * @param  array{int, int, int}  $tally
     * @return array{int, int, int}
     */
    private function convert(string $disk, string $path, string $label, bool $dry, array $tally): array
    {
        [$done, $already, $missing] = $tally;

        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            $this->warn('missing: '.$label);

            return [$done, $already, $missing + 1];
        }

        $raw = (string) Storage::disk($disk)->get($path);

        if (EncryptedFile::looksEncrypted($raw)) {
            return [$done, $already + 1, $missing];
        }

        $this->line('encrypting: '.$label);

        if ($dry) {
            return [$done + 1, $already, $missing];
        }

        // Written beside, then moved over the original. The move is a
        // rename, which replaces the destination in one step, so an
        // interrupted run leaves the original or the encrypted copy and
        // never half of either — and never nothing at all, which is what
        // deleting first would risk.
        $temporary = $path.'.encrypting';

        try {
            Storage::disk($disk)->put($temporary, EncryptedFile::wrap($raw));
            Storage::disk($disk)->move($temporary, $path);
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($temporary);
            $this->error('failed: '.$label.' — '.$e::class);

            return [$done, $already, $missing];
        }

        return [$done + 1, $already, $missing];
    }
}
