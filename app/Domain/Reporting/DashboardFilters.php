<?php

namespace App\Domain\Reporting;

use App\Models\MaintenanceRequest as Ticket;
use App\Models\Payment;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\Request;

/**
 * The six dashboard filters, as a value object.  [FR-ADM-01, AC-ADM-01]
 *
 * AC-ADM-01 asks for filter state in the URL, shareable and bookmarkable. That
 * makes the query string the source of truth rather than component state, and
 * it makes this the boundary where an arbitrary string from somebody else's
 * bookmark becomes something safe to put in a query.
 *
 * **An unrecognised value is dropped, not rejected.** A URL shared last month
 * may name a property since archived or a status since renamed; answering that
 * with a 422 punishes the reader for something the sender did. What comes back
 * in `toArray()` is what was actually applied, and the controls re-render from
 * it — so the screen and the filters always agree, even when the URL is stale.
 */
final class DashboardFilters
{
    /** Windows a person would actually pick, in days. */
    public const EXPIRY_WINDOWS = [30, 60, 90, 180];

    public const DEFAULT_EXPIRY_WINDOW = 90;

    /** `open` is not a stored status — it means "not closed and not cancelled". */
    public const MAINTENANCE_STATUSES = [
        'open',
        Ticket::STATUS_SUBMITTED,
        Ticket::STATUS_TRIAGED,
        Ticket::STATUS_ASSIGNED,
        Ticket::STATUS_SCHEDULED,
        Ticket::STATUS_IN_PROGRESS,
        Ticket::STATUS_AWAITING_CONFIRMATION,
        Ticket::STATUS_CLOSED,
        Ticket::STATUS_CANCELLED,
    ];

    public const PAYMENT_STATUSES = [
        Payment::STATUS_PENDING,
        Payment::STATUS_SETTLED,
        Payment::STATUS_RETURNED,
        Payment::STATUS_FAILED,
        Payment::STATUS_VOID,
    ];

    private function __construct(
        public readonly ?int $propertyId,
        public readonly ?int $tenantId,
        public readonly ?string $paymentStatus,
        public readonly ?string $maintenanceStatus,
        public readonly int $expiryDays,
        public readonly ?int $housingAuthorityId,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            propertyId: self::positiveInt($request->input('property')),
            tenantId: self::positiveInt($request->input('tenant')),
            paymentStatus: self::oneOf($request->input('payment_status'), self::PAYMENT_STATUSES),
            maintenanceStatus: self::oneOf($request->input('maintenance_status'), self::MAINTENANCE_STATUSES),
            expiryDays: self::window($request->input('expiry')),
            housingAuthorityId: self::positiveInt($request->input('housing_authority')),
        );
    }

    public static function none(): self
    {
        return new self(null, null, null, null, self::DEFAULT_EXPIRY_WINDOW, null);
    }

    /**
     * What was actually applied — the shape the URL and the controls share.
     *
     * The default expiry window is omitted, so an untouched dashboard has a
     * clean URL and "is anything filtered?" stays a simple question.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'property' => $this->propertyId,
            'tenant' => $this->tenantId,
            'payment_status' => $this->paymentStatus,
            'maintenance_status' => $this->maintenanceStatus,
            'expiry' => $this->expiryDays === self::DEFAULT_EXPIRY_WINDOW ? null : $this->expiryDays,
            'housing_authority' => $this->housingAuthorityId,
        ], fn ($value) => $value !== null);
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /** Property, tenant and housing authority narrow every panel that hangs off a lease. */
    public function applyToLeases(BuilderContract $query, string $table = 'leases'): BuilderContract
    {
        return $query
            ->when($this->propertyId, fn ($q, $id) => $q->whereIn(
                "{$table}.unit_id",
                fn ($sub) => $sub->select('id')->from('units')->where('property_id', $id),
            ))
            ->when($this->tenantId, fn ($q, $id) => $q->where("{$table}.tenant_id", $id))
            ->when($this->housingAuthorityId, fn ($q, $id) => $q->where("{$table}.housing_authority_id", $id));
    }

    public function applyToTickets(BuilderContract $query): BuilderContract
    {
        return $query
            ->when($this->tenantId, fn ($q, $id) => $q->where('maintenance_requests.tenant_id', $id))
            ->when($this->propertyId, fn ($q, $id) => $q->whereIn(
                'maintenance_requests.unit_id',
                fn ($sub) => $sub->select('id')->from('units')->where('property_id', $id),
            ))
            ->when(
                $this->maintenanceStatus === 'open',
                fn ($q) => $q->whereNotIn('maintenance_requests.status', [Ticket::STATUS_CLOSED, Ticket::STATUS_CANCELLED]),
                fn ($q) => $q->when(
                    $this->maintenanceStatus,
                    fn ($inner, $status) => $inner->where('maintenance_requests.status', $status),
                ),
            );
    }

    public function applyToPayments(BuilderContract $query): BuilderContract
    {
        return $query
            ->when($this->tenantId, fn ($q, $id) => $q->where('payments.tenant_id', $id))
            ->when($this->paymentStatus, fn ($q, $status) => $q->where('payments.status', $status))
            ->when($this->propertyId, fn ($q, $id) => $q->whereIn(
                'payments.lease_id',
                fn ($sub) => $sub->select('leases.id')->from('leases')
                    ->join('units', 'units.id', '=', 'leases.unit_id')
                    ->where('units.property_id', $id),
            ));
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private static function window(mixed $value): int
    {
        $days = is_numeric($value) ? (int) $value : 0;

        return in_array($days, self::EXPIRY_WINDOWS, true) ? $days : self::DEFAULT_EXPIRY_WINDOW;
    }
}
