<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Ledger\BalanceCalculator;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Money;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's own ledger.  [FR-POR-02, UI §3.4, API-POR-02/03]
 *
 * **Only `payer = tenant` rows exist on this path** (AC-POR-03). Not filtered
 * in the component, not hidden with CSS — the query never selects them, so
 * there is nothing in the payload for a later change to reveal.
 *
 * The running balance is recomputed here from the same rows the tenant sees,
 * so the last row's total always equals the balance on their dashboard. A
 * separately-derived figure would eventually disagree, and a statement that
 * disagrees with the dashboard is worse than no statement.
 */
class LedgerController extends Controller
{
    public function __construct(
        private readonly BalanceCalculator $balances,
        private readonly BusinessCalendar $calendar,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->tenantFor($request);
        [$from, $to] = $this->range($request);

        return Inertia::render('Portal/Ledger', [
            'entries' => $tenant ? $this->entries($tenant, $from, $to) : [],
            'balance' => $tenant ? (string) $this->balances->tenantBalance($tenant->id) : null,
            'pending' => $tenant ? (string) $this->balances->pendingPayments($tenant->id) : null,
            'unconfirmed' => $tenant ? (string) $this->balances->unconfirmedPayments($tenant->id) : null,
            'filters' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
        ]);
    }

    /** API-POR-03. The same rows, on paper. */
    public function export(Request $request): HttpResponse
    {
        $tenant = $this->tenantFor($request);

        abort_unless($tenant !== null, 404);

        [$from, $to] = $this->range($request);
        $entries = $this->entries($tenant, $from, $to);

        // Downloading your own statement is an ordinary act, but it is also the
        // document someone brings to a hearing. Worth knowing it was taken.
        $this->audit->record('portal.statement.downloaded', $tenant, [
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'rows' => count($entries),
        ]);

        $pdf = Pdf::loadView('pdf.statement', [
            'tenant' => $tenant,
            'entries' => $entries,
            'balance' => $this->balances->tenantBalance($tenant->id),
            'pending' => $this->balances->pendingPayments($tenant->id),
            'from' => $from,
            'to' => $to,
            'generatedOn' => $this->calendar->today(),
            'company' => [
                'name' => $this->settings->string('company.name', config('app.name')),
                'phone' => $this->settings->string('company.phone'),
                'address' => $this->settings->string('company.address'),
            ],
        ]);

        return $pdf->download(sprintf(
            'statement-%s-%s.pdf',
            str($tenant->fullName())->slug(),
            $this->calendar->today()->toDateString(),
        ));
    }

    /**
     * Entries with a running balance, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function entries(Tenant $tenant, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $query = LedgerEntry::query()
            // Needed to tell a payment in flight with a bank from a form
            // somebody opened and left. Eager, or it is an N+1 down the
            // longest list the portal renders.
            ->with('payment:id,gateway_transaction_id')
            ->where('tenant_id', $tenant->id)
            // AC-POR-03.
            ->where('payer', 'tenant')
            // An attempt that never became a payment is not history. See
            // DashboardController: showing void rows made abandoned attempts
            // read as "processing".
            ->where('status', '!=', 'void')
            ->orderBy('posted_on')
            ->orderBy('id');

        // The running balance has to start from the true opening position, or
        // a filtered view would show a total that is right for the window and
        // wrong for the account. So the filter narrows what is *shown*, not
        // what is counted.
        $running = Money::zero();
        $rows = [];

        foreach ($query->get() as $entry) {
            if ($entry->affectsBalance()) {
                $running = $running->plus($entry->amount);
            }

            $postedOn = $entry->posted_on;

            // Compared as DATES, not as instants. `posted_on` casts to midnight
            // UTC; the filter is parsed at midnight in Georgia, five hours
            // later. Comparing the two directly drops the very day a tenant
            // filtered from — the same timezone class of bug that stopped
            // WP-10's charge posting entirely (D-07).
            $day = $postedOn?->format('Y-m-d');

            if ($from && $day && $day < $from->format('Y-m-d')) {
                continue;
            }

            if ($to && $day && $day > $to->format('Y-m-d')) {
                continue;
            }

            $amount = $entry->amount;

            $rows[] = [
                'id' => $entry->id,
                'date' => $postedOn?->format('j F Y'),
                'iso_date' => $postedOn?->toDateString(),
                'description' => $entry->description,
                // Split into two columns, the way a statement reads: what was
                // added, and what came off.
                'charge' => $amount->isPositive() ? (string) $amount : null,
                'payment' => $amount->isNegative() ? (string) $amount->absolute() : null,
                'running' => (string) $running,
                'status' => $entry->status,
                // BR-05: shown, but it has not moved the balance yet.
                'counts' => $entry->affectsBalance(),
                'state' => DashboardController::stateLabel($entry->status, ! $entry->isUnconfirmedPayment()),
            ];
        }

        return $rows;
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function range(Request $request): array
    {
        $parse = function (?string $value): ?CarbonImmutable {
            if (! $value) {
                return null;
            }

            try {
                return CarbonImmutable::parse($value, $this->calendar->timezone())->startOfDay();
            } catch (\Throwable) {
                // A malformed date in a query string is not worth an error page
                // on a screen someone opened to read their own history.
                return null;
            }
        };

        return [$parse($request->query('from')), $parse($request->query('to'))];
    }

    private function tenantFor(Request $request): ?Tenant
    {
        $tenantId = $request->user()?->tenant_id;

        return $tenantId ? Tenant::find($tenantId) : null;
    }
}
