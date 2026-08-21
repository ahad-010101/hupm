<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Ledger\BalanceCalculator;
use App\Domain\Reporting\TenantAccountSummary;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Lease;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant dashboard.  [FR-POR-01, UI §3.1, API-POR-01]
 *
 * One screen that answers "what do I owe and what needs my attention", without
 * scrolling on a phone. That constraint is the design: the dominant persona
 * opens this once a month, on a small screen, usually because something is
 * worrying them.
 *
 * **AC-POR-01 is structural here.** Every figure is assembled by
 * TenantAccountSummary, which never reads the Housing Authority portion at
 * all. Nothing on this path filters it out, because nothing on this path picks
 * it up.
 */
class DashboardController extends Controller
{
    private const RECENT_ACTIVITY = 5;

    private const RECENT_DOCUMENTS = 3;

    public function __construct(
        private readonly TenantAccountSummary $summary,
        private readonly BalanceCalculator $balances,
        private readonly Settings $settings,
    ) {}

    public function __invoke(Request $request): Response
    {
        $tenant = Tenant::find($request->user()->tenant_id);

        if (! $tenant) {
            // A user with no tenant record is a data problem, not a 404: they
            // are legitimately signed in and need to be told something useful.
            return Inertia::render('Portal/Dashboard', [
                'balances' => ['balance' => null, 'pending' => null, 'error' => null],
                'orphaned' => true,
            ]);
        }

        $lease = $tenant->activeLease();
        $balances = $this->summary->balances($tenant);

        return Inertia::render('Portal/Dashboard', [
            ...$this->summary->for($tenant, $lease),
            'balances' => $balances,
            'due' => $this->summary->dueStatus(
                $lease,
                Money::fromString($balances['balance'] ?? '0'),
            ),
            // UI §3.1: if the balance could not be computed, the pay action goes
            // with it. Offering to take a payment against a figure we do not
            // trust is worse than offering nothing.
            'canPay' => $lease !== null
                && $balances['error'] === null
                && $lease->delinquency_state !== 'management_review',
            'recentActivity' => $this->recentActivity($tenant),
            'documents' => $this->recentDocuments($tenant),
            'notices' => $this->notices($tenant),
            'weatherAlerts' => $this->weatherAlerts($lease),
            'manager' => [
                'name' => $this->settings->string('company.name', config('app.name')),
                'phone' => $this->settings->string('company.phone'),
                'emergency_phone' => $this->settings->string('company.emergency_phone'),
                'address' => $this->settings->string('company.address'),
            ],
        ]);
    }

    /**
     * The last few things that happened, tenant rows only.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(Tenant $tenant): array
    {
        return LedgerEntry::query()
            ->with('payment:id,gateway_transaction_id')
            ->where('tenant_id', $tenant->id)
            // AC-POR-03. Not a display filter — the authority's rows never
            // leave the database on this request.
            ->where('payer', 'tenant')
            // A void entry is an attempt that never became a payment: the
            // gateway was unreachable, or they closed the tab. Nothing left
            // their account, so it is not part of their history — and showing
            // it made four abandoned attempts read as "processing", which is
            // money a tenant would think was on its way.
            ->where('status', '!=', 'void')
            ->orderByDesc('posted_on')
            ->orderByDesc('id')
            ->limit(self::RECENT_ACTIVITY)
            ->get()
            ->map(fn (LedgerEntry $entry) => [
                'id' => $entry->id,
                'date' => $entry->posted_on?->format('j F Y'),
                'description' => $entry->description,
                'amount' => (string) $entry->amount,
                'status' => $entry->status,
                'counts' => $entry->affectsBalance(),
                'state' => self::stateLabel($entry->status, ! $entry->isUnconfirmedPayment()),
            ])
            ->all();
    }

    /**
     * What to say about a row that has not moved the balance.
     *
     * "Processing" belongs to `pending` alone. A returned payment is a fact the
     * tenant needs stated plainly, and neither is ever called "failed" (UI §8).
     *
     * `$confirmed` is false when the gateway has never heard of the payment —
     * a form somebody opened and did not submit. Calling that "processing"
     * tells a resident their money is on its way to us when nothing was ever
     * sent, and the same screen is meanwhile telling them they are past due.
     */
    public static function stateLabel(string $status, bool $confirmed = true): ?string
    {
        if ($status === 'pending') {
            return $confirmed ? 'processing' : 'not completed';
        }

        return match ($status) {
            'returned' => 'returned by your bank',
            default => null,
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function recentDocuments(Tenant $tenant): array
    {
        return Document::query()
            ->where('tenant_id', $tenant->id)
            ->where('visible_to_tenant', true)
            ->orderByDesc('created_at')
            ->limit(self::RECENT_DOCUMENTS)
            ->get(['id', 'title', 'category', 'created_at'])
            ->map(fn (Document $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'category' => $document->category,
                'date' => $document->created_at?->format('j F Y'),
            ])
            ->all();
    }

    /**
     * Notices addressed to this tenant.
     *
     * Read-only until WP-20 builds composing and sending. The table exists and
     * the query is one join, so the dashboard is already wired for the day it
     * fills up rather than needing revisiting.
     *
     * @return array<int, array<string, mixed>>
     */
    private function notices(Tenant $tenant): array
    {
        return DB::table('notice_recipients as r')
            ->join('notices as n', 'n.id', '=', 'r.notice_id')
            ->where('r.tenant_id', $tenant->id)
            ->whereNotNull('n.sent_at')
            ->orderByDesc('n.sent_at')
            ->limit(3)
            ->get(['n.id', 'n.subject', 'n.sent_at'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'subject' => $row->subject,
                'sent_on' => $row->sent_at
                    ? Carbon::parse($row->sent_at)->format('j F Y')
                    : null,
            ])
            ->all();
    }

    /**
     * Live weather alerts for this tenant's property.
     *
     * Read-only until WP-21 polls the NWS. Expired alerts are excluded here
     * rather than left to the view: a storm warning that ended yesterday is
     * worse than no warning, because it teaches people to ignore the panel.
     *
     * @return array<int, array<string, mixed>>
     */
    private function weatherAlerts(?Lease $lease): array
    {
        $propertyId = $lease?->unit?->property_id;

        if (! $propertyId) {
            return [];
        }

        return DB::table('weather_alerts')
            ->where('property_id', $propertyId)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('effective_at')
            ->limit(3)
            ->get(['id', 'event_type', 'severity', 'headline'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'event' => $row->event_type,
                'severity' => $row->severity,
                'headline' => $row->headline,
            ])
            ->all();
    }
}
