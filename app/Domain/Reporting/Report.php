<?php

namespace App\Domain\Reporting;

/**
 * One finished report, in a shape the screen and both exports can all read.
 *
 * Five reports, one structure. The alternative — each report knowing how to
 * render itself as a table, as CSV and as a PDF — is fifteen renderers that
 * drift apart, and the first symptom is a total that differs between the screen
 * and the file somebody emailed to an owner.
 *
 * Money crosses into this object as **decimal strings**, already formatted by
 * `Money`, never as numbers (I-10). A report is a presentation of figures that
 * have already been computed; nothing here does arithmetic.
 */
final class Report
{
    /**
     * @param  list<array{key: string, label: string, money?: bool, balance?: bool}>  $columns
     *                                                                                          `balance` marks a column whose negative values are credits. UI §8 is
     *                                                                                          unqualified about it: a negative balance reads "Credit \$X", never a
     *                                                                                          minus figure. Exports still write the signed decimal, because a
     *                                                                                          spreadsheet has to be able to sum the column.
     * @param  list<array<string, string|int|null>>  $rows
     * @param  array<string, string|int|null>  $totals  Keyed by column, rendered as a footer row.
     * @param  list<string>  $notes  Shown under the table and carried into both exports.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $totals = [],
        public readonly array $notes = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'totals' => $this->totals,
            'notes' => $this->notes,
        ];
    }

    /** A filename stem an export can use. Dated, so two downloads never collide. */
    public function filename(string $stamp): string
    {
        return $this->key.'-'.$stamp;
    }
}
