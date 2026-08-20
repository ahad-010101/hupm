<?php

namespace App\Domain\Ledger;

use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Balances, derived at read time.  [FR-LED-02, BR-03, INVARIANT I-1]
 *
 * **Never cached and never stored.** TDD §7 is explicit, and the reason is not
 * performance dogma: a cached balance is a second source of truth, and the
 * moment it disagrees with the ledger there is no way to tell which one is
 * wrong. Summing 30 rows is cheap; explaining a stale figure to a tenant is
 * not.
 *
 * That also gives AC-LED-04 for free — asking twice cannot produce two answers
 * when there is nothing between the question and the rows.
 */
class BalanceCalculator
{
    /**
     * What the tenant owes.  [FR-LED-02, AC-LED-02]
     *
     * `payer = 'tenant'` only. The Housing Authority portion is a separate
     * obligation (BR-01) and must never reach a tenant-facing figure (I-4).
     */
    public function tenantBalance(int $tenantId): Money
    {
        return $this->sum($tenantId, 'tenant');
    }

    /** What the Housing Authority owes. Admin and owner only (§5.3). */
    public function haBalance(int $tenantId): Money
    {
        return $this->sum($tenantId, 'housing_authority');
    }

    /**
     * Every tenant's balance in one query.  [FR-ADM-01]
     *
     * The portfolio dashboard needs 26 balances at once, and 26 round trips to
     * ask the same question 26 times is the shape that turns into 260 when the
     * portfolio grows. Same derivation, same statuses, same read-time rule
     * (I-1) — only the grouping differs.
     *
     * Tenants with no ledger rows are absent rather than zero. A caller wanting
     * "everyone" should start from the tenant list and default the misses,
     * because "no entries" and "settled up" are different facts.
     *
     * @return array<int, Money> Keyed by tenant id.
     */
    public function balancesByTenant(string $payer = 'tenant'): array
    {
        $rows = DB::table('ledger_entries')
            ->select('tenant_id', DB::raw('SUM(amount) as total'))
            ->where('payer', $payer)
            ->whereIn('status', LedgerService::BALANCE_AFFECTING)
            ->groupBy('tenant_id')
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            $balances[(int) $row->tenant_id] = Money::fromString((string) ($row->total ?: '0'));
        }

        return $balances;
    }

    /**
     * Charges with something still outstanding, oldest first.
     *
     * Outstanding = the charge, less allocations that have not been reversed
     * (D-02). A returned payment restores its charges to unpaid, which is the
     * whole reason `reversed_at` exists rather than deleting the allocation.
     *
     * This is what WP-11's waterfall consumes.
     *
     * @return Collection<int, object{id:int, posted_on:string, category:string, description:string, amount:Money, allocated:Money, outstanding:Money}>
     */
    public function outstandingCharges(int $tenantId, string $payer = 'tenant'): Collection
    {
        $rows = DB::table('ledger_entries as e')
            ->leftJoin('payment_allocations as a', function ($join) {
                $join->on('a.charge_entry_id', '=', 'e.id')->whereNull('a.reversed_at');
            })
            ->where('e.tenant_id', $tenantId)
            ->where('e.payer', $payer)
            ->whereIn('e.type', ['charge', 'adjustment'])
            ->whereIn('e.status', LedgerService::BALANCE_AFFECTING)
            ->groupBy('e.id', 'e.posted_on', 'e.category', 'e.description', 'e.amount')
            ->orderBy('e.posted_on')
            ->orderBy('e.id')
            ->get([
                'e.id', 'e.posted_on', 'e.category', 'e.description', 'e.amount',
                DB::raw('COALESCE(SUM(a.amount), 0) as allocated'),
            ]);

        return $rows
            ->map(function ($row) {
                $amount = Money::fromString((string) $row->amount);
                $allocated = Money::fromString((string) $row->allocated);

                return (object) [
                    'id' => (int) $row->id,
                    'posted_on' => $row->posted_on,
                    'category' => $row->category,
                    'description' => $row->description,
                    'amount' => $amount,
                    'allocated' => $allocated,
                    'outstanding' => $amount->minus($allocated),
                ];
            })
            // A reversed or fully paid charge has nothing left to allocate
            // against; a credit adjustment is not something to "pay off".
            ->filter(fn ($charge) => $charge->outstanding->isPositive())
            ->values();
    }

    /**
     * What is still owed, grouped by charge period.
     *
     * A different question from outstandingCharges(): the waterfall asks "which
     * charge next", this asks "is February settled". Late fees are assessed per
     * period (BR-08/BR-09), so a tenant who paid February but not March is late
     * on March alone — which one combined balance figure cannot express.
     *
     * Rows with no period (an adjustment, a fee posted outside a cycle) are
     * excluded: they belong to no cycle and cannot make one late.
     *
     * @return array<string, Money> period => amount still outstanding
     */
    public function outstandingByPeriod(int $tenantId, string $payer = 'tenant'): array
    {
        $rows = DB::table('ledger_entries as e')
            ->leftJoin('payment_allocations as a', function ($join) {
                $join->on('a.charge_entry_id', '=', 'e.id')->whereNull('a.reversed_at');
            })
            ->where('e.tenant_id', $tenantId)
            ->where('e.payer', $payer)
            ->whereIn('e.type', ['charge', 'adjustment'])
            ->whereIn('e.status', LedgerService::BALANCE_AFFECTING)
            ->whereNotNull('e.period')
            ->groupBy('e.id', 'e.period', 'e.amount')
            ->orderBy('e.period')
            ->get([
                'e.period', 'e.amount',
                DB::raw('COALESCE(SUM(a.amount), 0) as allocated'),
            ]);

        $byPeriod = [];

        foreach ($rows as $row) {
            $outstanding = Money::fromString((string) $row->amount)
                ->minus(Money::fromString((string) $row->allocated));

            $byPeriod[$row->period] = ($byPeriod[$row->period] ?? Money::zero())->plus($outstanding);
        }

        // A period settled by a credit can come out negative; it is not late.
        return array_filter($byPeriod, fn (Money $amount) => $amount->isPositive());
    }

    /**
     * Every entry for a payer, with a running total.
     *
     * Ordered by effective date then id, so the running total is reproducible:
     * two entries posted on the same day must always appear in the same order,
     * or the column changes between page loads for no reason.
     *
     * @return Collection<int, object>
     */
    public function runningBalance(int $tenantId, string $payer = 'tenant'): Collection
    {
        $entries = LedgerEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('payer', $payer)
            ->orderBy('posted_on')
            ->orderBy('id')
            ->get();

        $running = Money::zero();

        return $entries->map(function (LedgerEntry $entry) use (&$running) {
            // A pending or void entry is shown but does not move the total
            // (BR-05) — the tenant needs to see that their payment is being
            // processed without the balance pretending it has cleared.
            if (in_array($entry->status, LedgerService::BALANCE_AFFECTING, true)) {
                $running = $running->plus($entry->amount);
            }

            return (object) [
                'entry' => $entry,
                'running' => $running,
                'counts' => in_array($entry->status, LedgerService::BALANCE_AFFECTING, true),
            ];
        });
    }

    /** Payments submitted but not yet cleared — shown beside the balance, never inside it. */
    public function pendingPayments(int $tenantId): Money
    {
        $total = DB::table('ledger_entries')
            ->where('tenant_id', $tenantId)
            ->where('payer', 'tenant')
            ->where('type', 'payment')
            ->where('status', 'pending')
            ->sum('amount');

        // Stored negative; presented as a positive "processing" figure.
        return Money::fromString((string) ($total ?: '0'))->absolute();
    }

    private function sum(int $tenantId, string $payer): Money
    {
        $total = DB::table('ledger_entries')
            ->where('tenant_id', $tenantId)
            ->where('payer', $payer)
            ->whereIn('status', LedgerService::BALANCE_AFFECTING)
            ->sum('amount');

        // MySQL returns DECIMAL as a string; keep it one all the way into Money
        // rather than letting it become a float on the way past (I-10).
        return Money::fromString((string) ($total ?: '0'));
    }
}
