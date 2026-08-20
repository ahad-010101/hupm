<?php

namespace App\Domain\Reporting;

use App\Models\MaintenanceRequest as Ticket;
use App\Models\Tenant;
use App\Models\Unit;

/**
 * One box, three kinds of answer.  [FR-ADM-01 "global search"]
 *
 * Tenant name, unit and ticket number — the three things somebody on the phone
 * reads out. Nothing here searches ledger amounts or document contents: a
 * search that returns everything returns nothing usefully, and the one that
 * matters when a resident rings is "who are you and which unit".
 *
 * Every result carries the screen it opens, so a search is a way of navigating
 * rather than a report about matches.
 */
class GlobalSearch
{
    private const PER_GROUP = 8;

    /** Below this a search matches most of a 26-tenant portfolio and helps nobody. */
    private const MINIMUM_TERM = 2;

    /**
     * @return array{term:string, total:int, tenants:list<array<string,mixed>>, units:list<array<string,mixed>>, tickets:list<array<string,mixed>>}
     */
    public function search(string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MINIMUM_TERM) {
            return ['term' => $term, 'total' => 0, 'tenants' => [], 'units' => [], 'tickets' => []];
        }

        $tenants = $this->tenants($term);
        $units = $this->units($term);
        $tickets = $this->tickets($term);

        return [
            'term' => $term,
            'total' => count($tenants) + count($units) + count($tickets),
            'tenants' => $tenants,
            'units' => $units,
            'tickets' => $tickets,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function tenants(string $term): array
    {
        $like = $this->like($term);

        return Tenant::query()
            ->where(fn ($q) => $q
                ->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ? ESCAPE '\\\\'", [$like])
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like))
            ->with(['leases' => fn ($q) => $q->where('status', 'active')->with('unit.property:id,name')])
            ->orderBy('last_name')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(function (Tenant $tenant) {
                $lease = $tenant->leases->first();

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->fullName(),
                    'detail' => $lease?->unit
                        ? trim(sprintf('%s unit %s', $lease->unit->property?->name ?? '', $lease->unit->unit_number))
                        : 'No active lease',
                    'href' => "/admin/tenants/{$tenant->id}",
                    // Straight to the money, because that is what the caller
                    // is usually ringing about.
                    'ledger_href' => "/admin/ledger/{$tenant->id}",
                ];
            })
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function units(string $term): array
    {
        $like = $this->like($term);

        return Unit::query()
            ->where(fn ($q) => $q
                ->where('unit_number', 'like', $like)
                ->orWhereHas('property', fn ($p) => $p->where('name', 'like', $like)
                    ->orWhere('street_address', 'like', $like)))
            ->with(['property:id,name,street_address', 'leases' => fn ($q) => $q->where('status', 'active')->with('tenant:id,first_name,last_name')])
            ->orderBy('unit_number')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(function (Unit $unit) {
                $lease = $unit->leases->first();

                return [
                    'id' => $unit->id,
                    'name' => trim(sprintf('%s unit %s', $unit->property?->name ?? '', $unit->unit_number)),
                    'detail' => $lease?->tenant ? $lease->tenant->fullName() : 'Vacant',
                    'href' => "/admin/properties/{$unit->property_id}",
                ];
            })
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function tickets(string $term): array
    {
        return Ticket::query()
            ->where('ticket_number', 'like', $this->like($term))
            ->with(['tenant:id,first_name,last_name'])
            ->orderByDesc('created_at')
            ->limit(self::PER_GROUP)
            ->get()
            ->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'name' => $ticket->ticket_number,
                'detail' => trim(sprintf(
                    '%s — %s',
                    $ticket->tenant?->fullName() ?? 'unknown resident',
                    $ticket->status,
                )),
                'href' => "/admin/maintenance/{$ticket->id}",
            ])
            ->all();
    }

    /**
     * A contains-match with the wildcards taken out of the caller's hands.
     *
     * `%` and `_` are meaningful to LIKE, so a resident called "A_B" or a search
     * for "%" would otherwise match half the portfolio. Escaped here rather
     * than at three call sites.
     */
    private function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }
}
