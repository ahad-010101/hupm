import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/EmptyState';
import ExceptionList from '@/Components/ExceptionList';
import Money from '@/Components/Money';
import StatusBadge from '@/Components/StatusBadge';

/**
 * Admin dashboard.  [API-ADM-01, FR-ADM-01, UI §3.7]
 *
 * The order on this page is the design: **exceptions, then numbers, then
 * panels**. A dashboard that opens with a KPI row is one where the returned
 * payment is below the fold, and the whole reason UI §3.7 names the exceptions
 * panel first is that nobody scrolls a dashboard they have already glanced at.
 *
 * Filters go through the URL (AC-ADM-01), never through component state, so a
 * filtered dashboard is something you can bookmark or send to a colleague.
 */

const MAINTENANCE_LABELS = {
    open: 'Any open ticket',
    submitted: 'Submitted',
    triaged: 'Triaged',
    assigned: 'Assigned',
    scheduled: 'Scheduled',
    in_progress: 'In progress',
    awaiting_tenant_confirmation: 'Awaiting resident confirmation',
    closed: 'Closed',
    cancelled: 'Cancelled',
};

/**
 * Admin wording, matching the payments screen.
 *
 * "Pending", not the portal's "Processing": the tenant-safe phrasing exists so
 * a resident is never told their ACH failed (UI §8), and softening it here
 * would hide from the office the very state it needs to chase.
 */
const PAYMENT_LABELS = {
    pending: 'Pending',
    settled: 'Settled',
    returned: 'Returned',
    failed: 'Failed',
    void: 'Void',
};

/**
 * A panel row, and the link that owns it.
 *
 * The link is stretched over the whole row (`after:inset-0`) rather than left
 * as 21px of underlined text. UI §6 asks for 44×44 touch targets and the
 * manager triages from the car; a name you have to hit exactly is a name you
 * miss. The row is the target, the link keeps the accessible name.
 */
const ROW = 'relative flex items-center justify-between gap-3 py-2';

const ROW_LINK =
    'text-base font-medium text-brand-700 underline after:absolute after:inset-0 ' +
    'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600';

function FilterSelect({ name, label, value, onChange, children }) {
    return (
        <label className="block text-sm">
            <span className="mb-1 block font-medium text-gray-700">{label}</span>
            <select
                value={value}
                onChange={(event) => onChange(name, event.target.value)}
                className="min-h-touch w-full rounded-md border-gray-300 text-base"
            >
                {children}
            </select>
        </label>
    );
}

function Panel({ title, action, children, className = '' }) {
    return (
        <section className={`rounded-lg border border-gray-200 bg-white ${className}`}>
            <div className="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <h2 className="text-base font-semibold text-gray-900">{title}</h2>
                {action}
            </div>
            <div className="p-4">{children}</div>
        </section>
    );
}

function Kpi({ label, children, sub }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <p className="text-sm font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className="mt-1 text-2xl font-semibold text-gray-900">{children}</p>
            {sub && <p className="mt-1 text-sm text-gray-600">{sub}</p>}
        </div>
    );
}

/** UI §7: a setup checklist, never a grid of zeroes. */
function SetupChecklist({ steps }) {
    return (
        <section className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="text-lg font-semibold text-gray-900">Let us get the portfolio set up</h2>
            <p className="mt-1 max-w-prose text-base text-gray-600">
                There are no active leases yet, so there is nothing to report on. These five steps put
                that right, in this order — each one needs the one above it.
            </p>

            <ol className="mt-4 space-y-3">
                {steps.map((step, index) => (
                    <li key={step.label} className="flex items-start gap-3">
                        <span
                            aria-hidden="true"
                            className={`mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-sm font-semibold ${
                                step.done ? 'bg-settled-bg text-settled-fg' : 'bg-gray-100 text-gray-600'
                            }`}
                        >
                            {step.done ? '✓' : index + 1}
                        </span>
                        <span className="min-w-0">
                            <Link
                                href={step.href}
                                className="text-base font-medium text-brand-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            >
                                {step.label}
                            </Link>
                            <span className="sr-only">{step.done ? ' — done' : ' — not started'}</span>
                            <span className="block text-base text-gray-600">{step.hint}</span>
                        </span>
                    </li>
                ))}
            </ol>
        </section>
    );
}

function Filters({ filters, options }) {
    // One place that writes the URL. Every control funnels through here so the
    // query string stays the single source of filter state (AC-ADM-01).
    const apply = (key, value) => {
        const next = { ...filters };

        if (value === '' || value === null) {
            delete next[key];
        } else {
            next[key] = value;
        }

        router.get('/admin', next, { preserveScroll: true, preserveState: true });
    };

    const active = Object.keys(filters).length > 0;

    // Every control is the same FilterSelect, declared at module scope and used
    // directly. Wrapping it in a local component would make it a new type on
    // every render, which React treats as a different element — it unmounts and
    // remounts, and the control loses focus mid-interaction.
    const Select = FilterSelect;
    const shared = { onChange: apply };

    return (
        <section aria-label="Filters" className="rounded-lg border border-gray-200 bg-white p-4">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <Select {...shared} name="property" label="Property" value={filters.property ?? ''}>
                    <option value="">All properties</option>
                    {options.properties.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>

                <Select {...shared} name="tenant" label="Resident" value={filters.tenant ?? ''}>
                    <option value="">All residents</option>
                    {options.tenants.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>

                <Select {...shared} name="housing_authority" label="Housing authority" value={filters.housing_authority ?? ''}>
                    <option value="">All authorities</option>
                    {options.housingAuthorities.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </Select>

                <Select {...shared} name="payment_status" label="Payment status" value={filters.payment_status ?? ''}>
                    <option value="">Any status</option>
                    {options.paymentStatuses.map((status) => (
                        <option key={status} value={status}>
                            {PAYMENT_LABELS[status] ?? status}
                        </option>
                    ))}
                </Select>

                <Select {...shared} name="maintenance_status" label="Maintenance status" value={filters.maintenance_status ?? ''}>
                    <option value="">Any status</option>
                    {options.maintenanceStatuses.map((status) => (
                        <option key={status} value={status}>
                            {MAINTENANCE_LABELS[status] ?? status}
                        </option>
                    ))}
                </Select>

                <Select {...shared} name="expiry" label="Leases expiring within" value={filters.expiry ?? 90}>
                    {options.expiryWindows.map((days) => (
                        <option key={days} value={days}>
                            {days} days
                        </option>
                    ))}
                </Select>
            </div>

            {active && (
                <p className="mt-3 text-sm">
                    <Link
                        href="/admin"
                        className="font-medium text-brand-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Clear all filters
                    </Link>
                    <span className="ml-2 text-gray-600">
                        This view is in the address bar — copy it to share exactly what you are looking at.
                    </span>
                </p>
            )}
        </section>
    );
}

export default function Dashboard({ exceptions = [], setup, kpis, panels, filters = {}, filterOptions }) {
    if (setup?.empty) {
        return (
            <AdminLayout header="Dashboard">
                <Head title="Dashboard" />
                <ExceptionList items={exceptions} limit={5} className="mb-6" />
                <SetupChecklist steps={setup.steps} />
            </AdminLayout>
        );
    }

    return (
        <AdminLayout header="Dashboard">
            <Head title="Dashboard" />

            {/* 1 — Exceptions. First, always (UI §3.7). */}
            <section aria-labelledby="exceptions-heading" className="mb-6">
                <div className="mb-2 flex items-center justify-between gap-3">
                    <h2 id="exceptions-heading" className="text-base font-semibold text-gray-900">
                        Needs attention
                    </h2>
                    {exceptions.length > 0 && (
                        <Link
                            href="/admin/exceptions"
                            className="inline-flex min-h-touch items-center text-base font-medium text-brand-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            See all
                        </Link>
                    )}
                </div>
                <ExceptionList items={exceptions} limit={5} />
            </section>

            {/* 2 — Filters, then the numbers they change. */}
            <div className="mb-6">
                <Filters filters={filters} options={filterOptions} />
            </div>

            {/* 3 — KPI row. */}
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Kpi
                    label="Occupancy"
                    sub={`${kpis.vacant} vacant of ${kpis.units_total}`}
                >
                    {kpis.occupancy_percent === null ? '—' : `${kpis.occupancy_percent}%`}
                </Kpi>

                <Kpi
                    label="Collected this month"
                    sub={
                        <>
                            Residents <Money value={kpis.collected_tenant} /> · Housing authority{' '}
                            <Money value={kpis.collected_ha} />
                        </>
                    }
                >
                    <Money value={kpis.collected_total} />
                </Kpi>

                <Kpi
                    label="Outstanding"
                    sub={
                        <>
                            Housing authority <Money value={kpis.outstanding_ha} />
                        </>
                    }
                >
                    <Money value={kpis.outstanding_tenant} balance />
                </Kpi>

                <Kpi label="Accounts past due" sub={`Rent period ${kpis.period}`}>
                    {kpis.past_due_count}
                </Kpi>
            </div>

            {/* 4 — Panels, in the order UI §3.7 fixes them. */}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Panel title="Past-due accounts">
                    {panels.past_due.length === 0 ? (
                        <EmptyState
                            title="No account is past due"
                            description="Every resident is inside their grace period or paid up."
                        />
                    ) : (
                        <ul className="divide-y divide-gray-200">
                            {panels.past_due.map((row) => (
                                <li key={row.lease_id} className={ROW}>
                                    <div className="min-w-0">
                                        <Link
                                            href={row.href}
                                            className={ROW_LINK}
                                        >
                                            {row.tenant}
                                        </Link>
                                        <p className="truncate text-base text-gray-600">
                                            {row.unit}
                                            {row.phone ? ` · ${row.phone}` : ''}
                                        </p>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <Money value={row.balance} balance className="block font-semibold" />
                                        <span className="block text-sm text-gray-600">
                                            {row.days_past_due} day{row.days_past_due === 1 ? '' : 's'} past due
                                        </span>
                                        {row.in_review && (
                                            <StatusBadge status="management_review" className="mt-1" />
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel
                    title="Open tickets"
                    action={
                        <span className="text-sm text-gray-600">
                            {panels.tickets.open_total} open · {panels.tickets.emergency_total} emergency
                        </span>
                    }
                >
                    {panels.tickets.items.length === 0 ? (
                        <EmptyState
                            title="No open maintenance tickets"
                            description="Anything new will appear here and in the exceptions panel if it is an emergency."
                        />
                    ) : (
                        <ul className="divide-y divide-gray-200">
                            {panels.tickets.items.map((ticket) => (
                                <li key={ticket.id} className={ROW}>
                                    <div className="min-w-0">
                                        <Link
                                            href={ticket.href}
                                            className={ROW_LINK}
                                        >
                                            {ticket.ticket_number}
                                        </Link>
                                        {ticket.is_emergency && (
                                            <span className="ml-2 rounded bg-overdue-bg px-1.5 py-0.5 text-sm font-semibold text-overdue-fg">
                                                Emergency
                                            </span>
                                        )}
                                        <p className="truncate text-base text-gray-600">
                                            {ticket.tenant} · {ticket.unit}
                                        </p>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <StatusBadge
                                            status={ticket.status}
                                            label={MAINTENANCE_LABELS[ticket.status] ?? ticket.status}
                                        />
                                        <span className="mt-1 block text-sm text-gray-600">
                                            {ticket.age_days} day{ticket.age_days === 1 ? '' : 's'} old
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel title="Lease expirations">
                    {panels.expiring_leases.length === 0 ? (
                        <EmptyState
                            title="No lease ends in this window"
                            description="Widen the window in the filters above to look further ahead."
                        />
                    ) : (
                        <ul className="divide-y divide-gray-200">
                            {panels.expiring_leases.map((lease) => (
                                <li key={lease.lease_id} className={ROW}>
                                    <div className="min-w-0">
                                        <Link
                                            href={lease.href}
                                            className={ROW_LINK}
                                        >
                                            {lease.tenant}
                                        </Link>
                                        <p className="truncate text-base text-gray-600">{lease.unit}</p>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <span className="block text-base text-gray-900">{lease.ends_on}</span>
                                        <span className="block text-sm text-gray-600">
                                            in {lease.days_left} day{lease.days_left === 1 ? '' : 's'}
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel title="Awaiting signature">
                    {panels.awaiting_signature.length === 0 ? (
                        <EmptyState
                            title="Nothing is waiting to be signed"
                            description="Documents sent for signature appear here until they come back."
                        />
                    ) : (
                        <ul className="divide-y divide-gray-200">
                            {panels.awaiting_signature.map((request) => (
                                <li key={request.id} className={ROW}>
                                    <div className="min-w-0">
                                        <Link
                                            href={request.href}
                                            className={ROW_LINK}
                                        >
                                            {request.document}
                                        </Link>
                                        <p className="truncate text-base text-gray-600">{request.tenant}</p>
                                    </div>
                                    <div className="shrink-0 text-right text-sm text-gray-600">
                                        <span className="block">Sent {request.sent_on ?? '—'}</span>
                                        {request.expires_on && <span className="block">Expires {request.expires_on}</span>}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel title="Recent payments" className="lg:col-span-2">
                    {panels.recent_payments.length === 0 ? (
                        <EmptyState
                            title="No payments recorded yet"
                            description="Payments appear here as they are received, whether taken online or recorded in the office."
                        />
                    ) : (
                        <ul className="divide-y divide-gray-200">
                            {panels.recent_payments.map((payment) => (
                                <li key={payment.id} className={ROW}>
                                    <div className="min-w-0">
                                        <Link
                                            href={payment.href}
                                            className={ROW_LINK}
                                        >
                                            {payment.tenant ?? 'Unidentified payer'}
                                        </Link>
                                        <p className="truncate text-base text-gray-600">
                                            {payment.received_on} · {payment.method}
                                            {payment.payer === 'housing_authority' ? ' · housing authority' : ''}
                                        </p>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <Money value={payment.amount} className="block font-semibold" />
                                        {/* Admin screens show the real state, not
                                            the tenant-safe wording. */}
                                        <StatusBadge
                                            status={payment.status}
                                            label={PAYMENT_LABELS[payment.status] ?? payment.status}
                                            className="mt-1"
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </AdminLayout>
    );
}
