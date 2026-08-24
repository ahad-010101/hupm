<?php

namespace App\Domain\Reporting;

use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report, as a file.  [API-ADM-35]
 *
 * One exporter for all five, reading the same `Report` the screen renders, so a
 * total can never differ between what somebody looked at and what they emailed
 * to an owner.
 */
class ReportExporter
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * CSV, streamed.
     *
     * Two details that are only obvious after the first complaint:
     *
     * 1. A **UTF-8 BOM**, because Excel on Windows opens a BOM-less UTF-8 file
     *    as Windows-1252 and turns every en dash and pound sign into mojibake.
     *    A resident called "Muñoz" is the usual way this gets noticed.
     * 2. Money written as a **bare decimal** — no symbol, no thousands
     *    separator — so the cell arrives as a number Excel can sum rather than
     *    text it right-aligns and ignores.
     */
    public function csv(Report $report): StreamedResponse
    {
        $filename = $report->filename(now()->format('Y-m-d')).'.csv';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'wb');

            fwrite($out, "\xEF\xBB\xBF");

            // The heading travels with the file. A CSV in a downloads folder
            // six weeks later has to say what it is and when it was taken.
            fputcsv($out, [$report->title]);
            fputcsv($out, [$report->subtitle]);
            fputcsv($out, ['Taken', now()->format('j F Y H:i')]);
            fputcsv($out, []);

            fputcsv($out, array_column($report->columns, 'label'));

            foreach ($report->rows as $row) {
                fputcsv($out, $this->line($report, $row));
            }

            if ($report->totals !== []) {
                fputcsv($out, $this->line($report, $report->totals));
            }

            foreach ($report->notes as $note) {
                fputcsv($out, []);
                fputcsv($out, [$note]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** PDF, for the version that gets printed or attached to an email. */
    public function pdf(Report $report): Response
    {
        $pdf = Pdf::loadView('pdf.report', [
            'report' => $report,
            'company' => $this->settings->string('company.name', config('app.name')),
            'takenAt' => now()->format('j F Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($report->filename(now()->format('Y-m-d')).'.pdf');
    }

    /**
     * One row, in column order, with money left as a plain decimal.
     *
     * @param  array<string, string|int|null>  $row
     * @return list<string>
     */
    private function line(Report $report, array $row): array
    {
        return array_map(
            fn (array $column) => (string) ($row[$column['key']] ?? ''),
            $report->columns,
        );
    }
}
