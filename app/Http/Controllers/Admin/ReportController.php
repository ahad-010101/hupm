<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reporting\ReportExporter;
use App\Domain\Reporting\ReportRegistry;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reports.  [API-ADM-32…35, FR-ADM-02]
 *
 * Every figure is computed when the report is opened. **Nothing is cached**,
 * which is a departure from TDD §7's fifteen-minute aggregate cache and is
 * deliberate (D-26): every report here is a sum over ledger rows, so caching
 * one caches a balance — the thing BR-03 and I-1 forbid outright. At 27 units
 * the queries are milliseconds, and a cached total that disagreed with the
 * ledger screen beside it would be indistinguishable from a bug in the ledger.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly ReportExporter $exporter,
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /** API-ADM-32/33/34. */
    public function index(Request $request, string $report = 'rent-roll'): Response
    {
        abort_unless($this->reports->has($report), 404);

        $options = $this->optionsFrom($request);

        return Inertia::render('Admin/Reports/Index', [
            'available' => $this->reports->available(),
            'active' => $report,
            'report' => $this->reports->build($report, $options)->toArray(),
            'options' => $options,
            'periods' => $this->periods(),
            'paymentStatuses' => [
                Payment::STATUS_PENDING,
                Payment::STATUS_SETTLED,
                Payment::STATUS_RETURNED,
                Payment::STATUS_FAILED,
                Payment::STATUS_VOID,
            ],
        ]);
    }

    /**
     * API-ADM-35. The same report, as a file.
     *
     * Audited, unlike opening one on screen. A report leaving the building is a
     * different act from reading it: these carry every resident's name and what
     * they owe, and "who exported the arrears list, and when" is a question
     * that gets asked after the fact or not at all.
     */
    public function export(Request $request, string $report)
    {
        abort_unless($this->reports->has($report), 404);

        $format = $request->string('format')->lower()->value();

        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);

        $options = $this->optionsFrom($request);
        $built = $this->reports->build($report, $options);

        $this->audit->record('report.exported', null, [
            'report' => $report,
            'format' => $format,
            'options' => array_filter($options, fn ($v) => $v !== null),
            'rows' => count($built->rows),
        ]);

        return $format === 'csv'
            ? $this->exporter->csv($built)
            : $this->exporter->pdf($built);
    }

    /**
     * Only the options a report actually accepts, validated to a known value.
     *
     * @return array<string, string|null>
     */
    private function optionsFrom(Request $request): array
    {
        $period = $request->string('period')->value();
        $status = $request->string('status')->value();

        return [
            // A month that carries no ledger rows is not an error — it renders
            // an empty report, which is the honest answer for a month before
            // the portfolio existed.
            'period' => preg_match('/^\d{4}-\d{2}$/', $period) ? $period : null,
            'status' => in_array($status, [
                Payment::STATUS_PENDING,
                Payment::STATUS_SETTLED,
                Payment::STATUS_RETURNED,
                Payment::STATUS_FAILED,
                Payment::STATUS_VOID,
            ], true) ? $status : null,
        ];
    }

    /**
     * Months that actually have ledger activity, newest first.
     *
     * Offering a fixed list of the last twelve would invite somebody to open a
     * month from before the portfolio existed and read the empty result as a
     * fault.
     *
     * @return list<array{value: string, label: string}>
     */
    private function periods(): array
    {
        $periods = DB::table('ledger_entries')
            ->whereNotNull('period')
            ->distinct()
            ->orderByDesc('period')
            ->pluck('period');

        $current = $this->calendar->currentPeriod();

        if (! $periods->contains($current)) {
            $periods->prepend($current);
        }

        return $periods
            ->map(fn (string $period) => [
                'value' => $period,
                'label' => $this->calendar->dueDateFor($period, 1)->format('F Y'),
            ])
            ->values()
            ->all();
    }
}
