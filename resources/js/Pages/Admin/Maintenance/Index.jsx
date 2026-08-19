import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';

/**
 * The ticket queue.  [UI §3.5, §6, API-ADM-21]
 *
 * Cards rather than a table, at every width. The manager triages this from a
 * car park between viewings, and a spreadsheet on a phone is a spreadsheet
 * nobody reads — so each card carries the four things that decide what happens
 * next: who, where, what, and how long it has been waiting.
 *
 * Emergencies are first in the query (AC-MNT-04) and marked, not merely
 * coloured (UI §9).
 */

const SCOPES = [
    { value: 'open', label: 'Open' },
    { value: 'closed', label: 'Finished' },
    { value: 'all', label: 'All' },
];

export default function Index({ tickets, filters = {}, emergencyCount = 0, statuses = {}, flash = {} }) {
    const go = (params) => router.get('/admin/maintenance', params, { preserveScroll: true });

    return (
        <AdminLayout header="Maintenance">
            <Head title="Maintenance" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            {emergencyCount > 0 && (
                <Alert tone="error" className="mb-4" title="Urgent requests waiting">
                    {emergencyCount} urgent {emergencyCount === 1 ? 'request is' : 'requests are'} open.
                    They are at the top of the list.
                </Alert>
            )}

            <div className="mb-4 flex flex-wrap gap-2">
                <div className="flex flex-wrap gap-1" role="group" aria-label="Filter by scope">
                    {SCOPES.map((scope) => {
                        const active = (filters.scope ?? 'open') === scope.value;

                        return (
                            <button
                                key={scope.value}
                                type="button"
                                onClick={() => go(scope.value === 'open' ? {} : { scope: scope.value })}
                                aria-pressed={active}
                                className={`inline-flex min-h-touch items-center rounded-md border px-3 text-base focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                                    active
                                        ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700'
                                        : 'border-gray-300 bg-white text-gray-700'
                                }`}
                            >
                                {scope.label}
                            </button>
                        );
                    })}
                </div>

                <label htmlFor="status" className="sr-only">Filter by status</label>
                <select
                    id="status"
                    value={filters.status ?? ''}
                    onChange={(e) => go({ ...filters, status: e.target.value || undefined })}
                    className="min-h-touch rounded-md border-gray-300 text-base focus:border-brand-600 focus:ring-brand-600"
                >
                    <option value="">Any status</option>
                    {Object.entries(statuses).map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            </div>

            {tickets.data.length === 0 ? (
                <EmptyState
                    title="Nothing in this list."
                    description="Repair requests appear here the moment a resident submits one."
                />
            ) : (
                <ul className="space-y-2">
                    {tickets.data.map((ticket) => (
                        <li key={ticket.id}>
                            <Link
                                href={`/admin/maintenance/${ticket.id}`}
                                className={`block rounded-lg border bg-white p-4 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                                    ticket.is_emergency ? 'border-overdue-border' : 'border-gray-200'
                                }`}
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <span className="text-base font-semibold text-gray-900">
                                        {ticket.category}
                                    </span>
                                    <span className="text-sm text-gray-600">{ticket.ticket_number}</span>
                                </div>

                                <p className="mt-1 text-base text-gray-700">
                                    {ticket.tenant}
                                    {ticket.unit && <span className="text-gray-600"> · {ticket.unit}</span>}
                                </p>

                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    {ticket.is_emergency && (
                                        <StatusBadge status="urgent" label="Urgent" tone="overdue" />
                                    )}
                                    <StatusBadge
                                        status={ticket.status}
                                        label={ticket.status_label}
                                        tone="neutral"
                                    />
                                    {ticket.vendor && (
                                        <span className="text-sm text-gray-600">{ticket.vendor}</span>
                                    )}
                                    {/* Age, not date. "Waiting 9 days" is the fact
                                        that decides what to do; the date is not. */}
                                    <span className="text-sm text-gray-600">
                                        {ticket.age_days === 0
                                            ? 'Today'
                                            : `Waiting ${ticket.age_days} ${ticket.age_days === 1 ? 'day' : 'days'}`}
                                    </span>
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {tickets.last_page > 1 && (
                <nav aria-label="Pagination" className="mt-4 flex flex-wrap gap-1">
                    {tickets.links.map((link, i) => (
                        <Link
                            key={i}
                            href={link.url ?? '#'}
                            aria-current={link.active ? 'page' : undefined}
                            className={`inline-flex min-h-touch min-w-touch items-center justify-center rounded-md border px-3 text-base focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                                link.active
                                    ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700'
                                    : 'border-gray-300 bg-white text-gray-700'
                            } ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </nav>
            )}
        </AdminLayout>
    );
}
