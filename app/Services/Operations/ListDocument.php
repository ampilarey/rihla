<?php

namespace App\Services\Operations;

/**
 * A printable list: a title, columns, rows. Rendered as PDF or CSV without
 * knowing which, so the two can never disagree about what is on it.
 */
final class ListDocument
{
    /**
     * @param  array<string, string>  $columns  key => heading, in print order
     * @param  list<array<string, string|int|null>>  $rows  every key a row has; only the columns' keys print
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $columns,
        public readonly array $rows,
        public readonly bool $landscape = false,
        public readonly array $notes = [],
        public readonly string $filename = 'list',
    ) {}

    /** With a byte-order mark, so Excel opens a Dhivehi or Arabic name as letters and not as question marks. */
    public function csv(): string
    {
        $out = fopen('php://temp', 'r+');

        if ($out === false) {
            return '';
        }

        fputcsv($out, array_values($this->columns), ',', '"', '\\', "\n");

        foreach ($this->rows as $row) {
            fputcsv($out, array_map(fn (string $key): string => (string) ($row[$key] ?? ''), array_keys($this->columns)), ',', '"', '\\', "\n");
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF".$csv;
    }
}
