<?php

namespace Modules\Companies\Services;

use App\Casts\EncryptedIdentifier;
use App\Models\Partner;
use App\Support\EncryptedFile;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Everything a company holds, in one download — companies plan, Phase 5d
 * (decision 12: a company must always be able to leave).
 *
 * One CSV per table that carries the company — customers, travellers,
 * bookings, payments, the books, documents, the audit trail and the rest —
 * with every row of that company's and nobody else's, plus the files
 * themselves: passport and visa scans, payment slips. Open formats only: a
 * spreadsheet and the original images, readable without Rihla.
 *
 * Identifiers stored encrypted are written readable, because ciphertext
 * only this server's key can open is no use to a company that has left.
 * Credentials are not written at all: portal and family link tokens, chat
 * link codes, passwords — they let somebody in, and mean nothing elsewhere.
 */
final class CompanyExport
{
    /** Tables that hold only credentials or bot plumbing. */
    private const LEFT_OUT = ['portal_accesses', 'family_accesses', 'telegram_link_codes', 'telegram_updates'];

    /** Columns that let somebody in. */
    private const SECRET = '/(token|password|secret)/i';

    /** The zip's path on the local disk. */
    public function make(Partner $company): string
    {
        if (! $company->is_umrah_company || $company->is_rihla) {
            throw new RuntimeException('Only another Umrah company is exported here.');
        }

        $relative = 'exports/'.$company->slug.'-'.now()->format('Ymd-His').'.zip';
        $absolute = Storage::disk('local')->path($relative);
        @mkdir(dirname($absolute), 0770, true);

        $zip = new ZipArchive;

        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export could not be started on this disk.');
        }

        $id = (int) $company->getKey();
        $counts = [];

        foreach ($this->tables() as $table) {
            $counts[$table] = $this->table($zip, $table, $id);
        }

        $files = $this->files($zip, $id);

        $zip->addFromString('README.txt', implode("\n", [
            $company->name.' — everything held for you on Rihla',
            'Made '.now()->toDayDateTimeString().'.',
            '',
            'One CSV file per kind of record, opened by any spreadsheet. Dates are UTC.',
            'files/ holds the documents and payment slips themselves, named by record.',
            'Link tokens and passwords are not included: they only ever worked on Rihla.',
            '',
            'Rows per file:',
            ...array_map(fn (string $table, int $rows): string => "  {$table}.csv: {$rows}", array_keys($counts), $counts),
            "  files: {$files}",
        ])."\n");

        $zip->close();

        return $relative;
    }

    /** @return list<string> */
    private function tables(): array
    {
        $tables = array_map(fn (mixed $t): string => is_array($t) ? (string) $t['name'] : (string) $t, Schema::getTables());

        return array_values(array_filter(
            $tables,
            fn (string $table): bool => ! in_array($table, self::LEFT_OUT, true) && Schema::hasColumn($table, 'company_id'),
        ));
    }

    private function table(ZipArchive $zip, string $table, int $company): int
    {
        $columns = array_values(array_filter(Schema::getColumnListing($table), fn (string $c): bool => preg_match(self::SECRET, $c) !== 1));
        $out = fopen('php://temp', 'w+');
        fputcsv($out, $columns, escape: '\\');
        $rows = 0;

        DB::table($table)->where('company_id', $company)->orderBy($columns[0])->select($columns)
            ->chunk(500, function ($chunk) use ($out, $columns, &$rows): void {
                foreach ($chunk as $row) {
                    fputcsv($out, array_map(fn (string $c): mixed => $this->readable(((array) $row)[$c] ?? null), $columns), escape: '\\');
                    $rows++;
                }
            });

        rewind($out);
        $zip->addFromString($table.'.csv', (string) stream_get_contents($out));
        fclose($out);

        return $rows;
    }

    private function readable(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, EncryptedIdentifier::MAGIC)) {
            try {
                return Crypt::decryptString(substr($value, strlen(EncryptedIdentifier::MAGIC)));
            } catch (DecryptException) {
                return '';
            }
        }

        return $value;
    }

    /** The scans and slips themselves, decrypted. */
    private function files(ZipArchive $zip, int $company): int
    {
        $count = 0;

        foreach (DB::table('document_versions')->where('company_id', $company)->get(['id', 'document_id', 'disk', 'path']) as $version) {
            $bytes = EncryptedFile::contents((string) $version->disk, (string) $version->path);

            if ($bytes !== null) {
                $zip->addFromString('files/documents/'.$version->document_id.'/'.$version->id.'-'.basename((string) $version->path), $bytes);
                $count++;
            }
        }

        foreach (DB::table('payments')->where('company_id', $company)->whereNotNull('slip_path')->get(['id', 'slip_disk', 'slip_path']) as $payment) {
            $bytes = EncryptedFile::contents((string) ($payment->slip_disk ?: 'documents'), (string) $payment->slip_path);

            if ($bytes !== null) {
                $zip->addFromString('files/payment-slips/'.$payment->id.'-'.basename((string) $payment->slip_path), $bytes);
                $count++;
            }
        }

        return $count;
    }
}
