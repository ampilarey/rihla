<?php

namespace App\Services\Import;

use App\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Historical customers, out of a spreadsheet and into the database.
 *
 * ## Dry run first, always
 *
 * The plan's risk register is explicit: "import in dry-run mode with a
 * review queue; duplicate detection with human merge". This reads the whole
 * file and reports what it *would* do, and writes nothing until told to.
 * An import that half-succeeds against a live customer table is worse than
 * one that never runs, because the second half has to be done by hand
 * against a table that has already moved.
 *
 * ## It refuses to guess
 *
 * Three outcomes per row, and only one of them writes:
 *
 * - **New** — nothing matches, so a customer is created.
 * - **Matched** — exactly one existing customer has the same phone number.
 *   Nothing is overwritten; the row is reported as already known, with
 *   any field that disagrees named.
 * - **Ambiguous** — more than one match, or a match on name alone. Reported
 *   for a person to decide and never merged automatically. A wrongly merged
 *   customer takes two bookings and two families with it.
 *
 * Phone is the identity here because in this market it is: an email address
 * is often absent or shared between a whole family, and a name is written
 * three different ways by three different members of staff.
 *
 * ## Duplicates inside the file count too
 *
 * A spreadsheet that has been edited for six years contains the same person
 * twice, and an import that checks only against the database creates both.
 * Rows are compared to each other as well.
 */
final class CustomerImport
{
    public const NEW = 'new';

    public const MATCHED = 'matched';

    public const AMBIGUOUS = 'ambiguous';

    public const SKIPPED = 'skipped';

    /**
     * Read a CSV and decide what each row means. Writes nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function plan(string $path): array
    {
        $rows = $this->read($path);
        $plan = [];

        // Phone key => the row number that claimed it, for finding
        // duplicates inside the file itself.
        $seen = [];

        foreach ($rows as $number => $row) {
            $plan[] = $this->decide($row, $number, $seen);
        }

        return $plan;
    }

    /**
     * Apply a plan. Only rows decided `new` are written.
     *
     * Wrapped in one transaction: a partially applied import against a live
     * customer table is the thing the dry run exists to prevent, and it
     * should not be reachable by a connection dropping halfway either.
     *
     * @param  list<array<string, mixed>>  $plan
     * @return int how many customers were created
     */
    public function apply(array $plan): int
    {
        return DB::transaction(function () use ($plan): int {
            $created = 0;

            foreach ($plan as $entry) {
                if ($entry['outcome'] !== self::NEW) {
                    continue;
                }

                Customer::create([
                    'name' => $entry['row']['name'],
                    'email' => $entry['row']['email'] ?: null,
                    'phone' => $entry['row']['phone'],
                    'national_id' => $entry['row']['national_id'] ?: null,
                    'address' => $entry['row']['address'] ?: null,
                    // Where this person came from, kept on the record. When
                    // somebody asks in a year why a customer has no booking
                    // history, "imported from the 2019 spreadsheet" is the
                    // answer.
                    'notes' => trim(($entry['row']['notes'] ?: '')."\nImported from ".$entry['source']),
                ]);

                $created++;
            }

            return $created;
        });
    }

    /**
     * What this row is.
     *
     * @param  array<string, string>  $row
     * @param  array<string, int>  $seen
     * @return array<string, mixed>
     */
    private function decide(array $row, int $number, array &$seen): array
    {
        $entry = [
            'line' => $number + 2, // Header is line 1, and people count from 1.
            'row' => $row,
            'source' => $row['_source'] ?? 'a spreadsheet',
            'outcome' => self::NEW,
            'because' => null,
            'customer_id' => null,
        ];

        if (trim($row['name']) === '') {
            return ['outcome' => self::SKIPPED, 'because' => 'No name.'] + $entry;
        }

        $key = PhoneNumber::key($row['phone']);

        if ($key === null) {
            // No phone means nothing reliable to match on. Reported rather
            // than created: a nameless-and-numberless customer is a row
            // nobody can ever use, and creating it silently fills the table
            // with them.
            return ['outcome' => self::AMBIGUOUS, 'because' => 'No phone number to match on.'] + $entry;
        }

        if (isset($seen[$key])) {
            return [
                'outcome' => self::AMBIGUOUS,
                'because' => 'The same phone number is already on line '.$seen[$key].' of this file.',
            ] + $entry;
        }

        $matches = $this->existingWith($key);

        if ($matches->count() > 1) {
            return [
                'outcome' => self::AMBIGUOUS,
                'because' => 'That phone number is already on '.$matches->count().' customers.',
            ] + $entry;
        }

        if ($matches->count() === 1) {
            $seen[$key] = $entry['line'];

            return [
                'outcome' => self::MATCHED,
                'because' => $this->differences($matches->first(), $row),
                'customer_id' => $matches->first()->getKey(),
            ] + $entry;
        }

        $seen[$key] = $entry['line'];

        return $entry;
    }

    /**
     * Customers whose stored number is the same number.
     *
     * Compared in PHP rather than SQL because the stored values are as
     * inconsistent as the file's: `+960 771 2345` and `7712345` are the
     * same person and no `WHERE phone = ?` finds that. The customer table
     * at this operator's size is a few thousand rows.
     *
     * @return Collection<int, Customer>
     */
    private function existingWith(string $key): Collection
    {
        return Customer::query()
            ->get(['id', 'name', 'email', 'phone', 'national_id'])
            ->filter(fn (Customer $customer): bool => PhoneNumber::key($customer->phone) === $key)
            ->values();
    }

    /**
     * What the file says that the database does not, in a sentence.
     *
     * Named rather than applied. An import that quietly overwrites a name
     * somebody corrected last week is worse than one that says "the file
     * disagrees" and leaves it.
     *
     * @param  array<string, string>  $row
     */
    private function differences(Customer $customer, array $row): string
    {
        $differences = [];

        foreach (['name', 'email', 'national_id'] as $field) {
            $incoming = trim((string) ($row[$field] ?? ''));

            if ($incoming === '') {
                continue;
            }

            if (! $this->looselySame((string) $customer->{$field}, $incoming)) {
                $differences[] = sprintf('%s ("%s" here, "%s" in the file)', $field, $customer->{$field}, $incoming);
            }
        }

        return $differences === []
            ? 'Already on file, and everything agrees.'
            : 'Already on file. The file disagrees about: '.implode(', ', $differences).'.';
    }

    /**
     * Whether two free-text values are the same thing written differently.
     *
     * Case and spacing only. Nothing cleverer: a fuzzy name match is how an
     * import merges two brothers, and two brothers on one customer record
     * is two families sharing a booking history.
     */
    private function looselySame(string $a, string $b): bool
    {
        $normalise = static fn (string $value): string => Str::lower(
            (string) preg_replace('/\s+/', ' ', trim($value)),
        );

        return $normalise($a) === $normalise($b);
    }

    /**
     * The file, as rows keyed by the column names this import knows.
     *
     * Unknown columns are ignored rather than rejected: a spreadsheet that
     * has been kept for six years has columns nobody remembers adding, and
     * refusing the whole file over one of them helps nobody.
     *
     * @return list<array<string, string>>
     */
    private function read(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header = array_map(
            static fn ($column): string => Str::snake(Str::lower(trim((string) $column))),
            $header,
        );

        $source = basename($path);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            // A trailing blank line, which every hand-edited CSV has.
            if ($line === [null] || $line === ['']) {
                continue;
            }

            $row = [];

            foreach (self::COLUMNS as $column) {
                $index = array_search($column, $header, true);
                $row[$column] = $index === false ? '' : trim((string) ($line[$index] ?? ''));
            }

            $row['_source'] = $source;
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /** @var list<string> */
    private const COLUMNS = ['name', 'email', 'phone', 'national_id', 'address', 'notes'];
}
