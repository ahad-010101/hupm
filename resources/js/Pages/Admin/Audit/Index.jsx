import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';

/**
 * The audit trail.  [FR-AUD-01, API-ADM-41, UI §3]
 *
 * Read-only by construction — there is no control here that changes anything,
 * because there is no route that would accept it (AC-AUD-02).
 *
 * Every filter is a query parameter, so a view of the trail has a URL. That
 * matters more here than on most screens: the reason someone opens this page is
 * usually to answer a question from a resident or an auditor, and the answer
 * needs to be something you can send to somebody else.
 */

/**
 * `ledger.charge.posted` → { group: 'Ledger', detail: 'charge posted' }.
 *
 * Derived rather than looked up in a table of labels. Around seventy action
 * strings exist and every work package adds more; a hand-kept dictionary would
 * be missing exactly the newest entries, which are the ones being investigated.
 */
function readAction(action) {
    const [head, ...rest] = String(action).split('.');
    const humanise = (s) => s.replace(/_/g, ' ');

    return {
        group: humanise(head).replace(/^./, (c) => c.toUpperCase()),
        detail: rest.length ? humanise(rest.join(' ')) : null,
    };
}

/** A single changed value, whether it came from record() or recordChange(). */
function readValue(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'object') return JSON.stringify(value);
    if (typeof value === 'boolean') return value ? 'yes' : 'no';
    return String(value);
}

function Changes({ changes }) {
    const entries = Object.entries(changes ?? {});

    if (entries.length === 0) return <span className="text-gray-500">—</span>;

    const rows = entries.map(([field, value]) => {
        // recordChange() stores { from, to }; record() stores a plain summary.
        const isTransition =
            value && typeof value === 'object' && !Array.isArray(value) && 'to' in value;

        return (
            <div key={field} className="flex flex-wrap gap-x-1.5 text-sm">
                <span className="text-gray-600">{field.replace(/_/g, ' ')}</span>
                {isTransition ? (
                    <span className="text-gray-900">
                        {readValue(value.from)}
                        <span aria-label="changed to" className="px-1 text-gray-500">
                            →
                        </span>
                        {readValue(value.to)}
                    </span>
                ) : (
                    <span className="text-gray-900">{readValue(value)}</span>
                )}
            </div>
        );
    });

    // Two lines inline, the rest behind a disclosure: a settings change can
    // carry a dozen fields and would otherwise push every other row off screen.
    if (rows.length <= 2) return <div className="space-y-0.5">{rows}</div>;

    return (
        <details className="group">
            <summary className="cursor-pointer text-sm text-gray-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                {rows.length} fields
            </summary>
            <div className="mt-1 space-y-0.5">{rows}</div>
        </details>
    );
}

export default function Index({
    logs,
    filters = {},
    actions = {},
    subjectTypes = [],
    actors = [],
    timezone = 'America/New_York',
}) {
    // One helper for every control: merge this change over what is already
    // selected, drop the empties, and go. Changing a filter always returns to
    // page one — page 4 of a different question is not a meaningful place.
    const apply = (patch) => {
        const next = { ...filters, ...patch };

        Object.keys(next).forEach((key) => {
            if (next[key] === '' || next[key] === null || next[key] === undefined) delete next[key];
        });

        router.get('/admin/audit', next, { preserveScroll: true, preserveState: true });
    };

    const clear = () => router.get('/admin/audit', {}, { preserveScroll: true });

    const active = Object.values(filters).filter(
        (v) => v !== '' && v !== null && v !== undefined,
    ).length;

    const when = (iso) =>
        new Intl.DateTimeFormat('en-GB', {
            day: '2-digit',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
            timeZone: timezone,
        }).format(new Date(iso));

    const columns = [
        {
            key: 'at',
            header: 'When',
            render: (row) => <span className="whitespace-nowrap">{when(row.at)}</span>,
        },
        {
            key: 'actor',
            header: 'Who',
            render: (row) =>
                row.actor ? (
                    <span className="font-medium text-gray-900">{row.actor}</span>
                ) : (
                    // Not "nobody" — a rule ran. That distinction is the whole
                    // answer when a resident asks why a fee appeared overnight.
                    <span className="text-gray-600">System</span>
                ),
        },
        {
            key: 'action',
            header: 'Action',
            render: (row) => {
                const { group, detail } = readAction(row.action);

                return (
                    <span>
                        <span className="font-medium text-gray-900">{group}</span>
                        {detail && <span className="block text-sm text-gray-600">{detail}</span>}
                    </span>
                );
            },
        },
        {
            key: 'subject',
            header: 'Subject',
            render: (row) =>
                row.subject_type ? (
                    <span className="whitespace-nowrap">
                        {row.subject_type}
                        {row.subject_id != null && (
                            <span className="text-gray-600"> #{row.subject_id}</span>
                        )}
                    </span>
                ) : (
                    <span className="text-gray-500">—</span>
                ),
        },
        {
            key: 'changes',
            header: 'Detail',
            render: (row) => <Changes changes={row.changes} />,
        },
        {
            key: 'ip_address',
            header: 'IP',
            hideOnMobile: true,
            render: (row) => (
                <span className="text-sm text-gray-600">{row.ip_address ?? '—'}</span>
            ),
        },
    ];

    const control = 'min-h-touch w-full rounded-md border-gray-300 text-base';
    const labelText = 'mb-1 block text-sm font-medium text-gray-700';

    return (
        <AdminLayout header="Audit">
            <Head title="Audit" />

            <p className="mb-4 max-w-3xl text-base text-gray-700">
                Every ledger movement, payment event, delinquency change, notice, document access
                and signature, with who did it and when. Rows are added and never changed or
                removed.
            </p>

            <section className="mb-4 rounded-lg border border-gray-200 bg-white p-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    <label className="lg:col-span-1">
                        <span className={labelText}>Who</span>
                        <select
                            value={filters.actor ?? ''}
                            onChange={(e) => apply({ actor: e.target.value })}
                            className={control}
                        >
                            <option value="">Anyone</option>
                            <option value="system">System (scheduled jobs)</option>
                            {actors.map((a) => (
                                <option key={a.value} value={a.value}>
                                    {a.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="lg:col-span-2">
                        <span className={labelText}>Action</span>
                        <select
                            value={filters.action ?? ''}
                            onChange={(e) => apply({ action: e.target.value })}
                            className={control}
                        >
                            <option value="">Any action</option>
                            {Object.entries(actions).map(([group, list]) => (
                                <optgroup key={group} label={readAction(group).group}>
                                    {list.map((a) => (
                                        <option key={a} value={a}>
                                            {readAction(a).detail ?? a}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </select>
                    </label>

                    <label className="lg:col-span-1">
                        <span className={labelText}>Subject</span>
                        <select
                            value={filters.subject_type ?? ''}
                            onChange={(e) => apply({ subject_type: e.target.value })}
                            className={control}
                        >
                            <option value="">Anything</option>
                            {subjectTypes.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label>
                        <span className={labelText}>From</span>
                        <input
                            type="date"
                            value={filters.from ?? ''}
                            onChange={(e) => apply({ from: e.target.value })}
                            className={control}
                        />
                    </label>

                    <label>
                        <span className={labelText}>To</span>
                        <input
                            type="date"
                            value={filters.to ?? ''}
                            onChange={(e) => apply({ to: e.target.value })}
                            className={control}
                        />
                    </label>
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-3">
                    <p className="text-sm text-gray-600">
                        {logs.total.toLocaleString()} {logs.total === 1 ? 'entry' : 'entries'}
                        {active > 0 && ' matching'} · times shown in {timezone.replace('_', ' ')}
                    </p>

                    {active > 0 && (
                        <button
                            type="button"
                            onClick={clear}
                            className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-3 text-base font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            Clear filters
                        </button>
                    )}
                </div>
            </section>

            <DataTable
                caption="Audit trail"
                columns={columns}
                rows={logs.data}
                empty={
                    <EmptyState
                        title={active > 0 ? 'Nothing matches those filters.' : 'Nothing recorded yet.'}
                        description={
                            active > 0
                                ? 'Try widening the dates, or clear the filters to see everything.'
                                : 'Actions are recorded here as they happen.'
                        }
                    />
                }
            />

            {logs.last_page > 1 && (
                <nav aria-label="Pagination" className="mt-4 flex flex-wrap gap-1">
                    {logs.links.map((link, i) => (
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
