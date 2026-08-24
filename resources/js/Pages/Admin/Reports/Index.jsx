import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/EmptyState';
import Money from '@/Components/Money';

/**
 * Reports.  [API-ADM-32…35, FR-ADM-02]
 *
 * Five reports, one table. The server hands back a uniform shape — columns,
 * rows, totals, notes — so the screen and both exports read the same object and
 * a figure cannot differ between what somebody looked at and what they emailed
 * to an owner.
 *
 * The notes under each table are part of the report, not decoration. "Received
 * counts only money that has cleared" is the difference between a number being
 * read correctly and being queried three weeks later.
 */

export default function Index({
    available = [],
    active,
    report,
    options = {},
    periods = [],
    paymentStatuses = [],
}) {
    const go = (key, extra = {}) => {
        const params = { ...options, ...extra };
        const query = Object.fromEntries(Object.entries(params).filter(([, v]) => v));

        router.get(`/admin/reports/${key}`, query, { preserveScroll: true });
    };

    const exportHref = (format) => {
        const query = new URLSearchParams(
            Object.entries({ ...options, format }).filter(([, v]) => v),
        );

        return `/admin/reports/${active}/export?${query}`;
    };

    return (
        <AdminLayout header="Reports">
            <Head title={report.title} />

            {/* Picker. Every report is one click away, with a line saying what
                it answers — the names alone do not distinguish a rent roll from
                a collections report to somebody who has not used both. */}
            <div className="mb-5 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                {available.map((item) => {
                    const current = item.key === active;

                    return (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => go(item.key)}
                            aria-current={current ? 'page' : undefined}
                            className={`rounded-lg border p-3 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                                current
                                    ? 'border-brand-600 bg-brand-50'
                                    : 'border-gray-200 bg-white hover:bg-gray-50'
                            }`}
                        >
                            <span
                                className={`block text-base font-semibold ${
                                    current ? 'text-brand-700' : 'text-gray-900'
                                }`}
                            >
                                {item.label}
                            </span>
                            <span className="mt-0.5 block text-sm text-gray-600">{item.blurb}</span>
                        </button>
                    );
                })}
            </div>

            <section className="rounded-lg border border-gray-200 bg-white">
                <div className="flex flex-wrap items-end justify-between gap-3 border-b border-gray-200 p-4">
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold text-gray-900">{report.title}</h2>
                        <p className="text-base text-gray-600">{report.subtitle}</p>
                    </div>

                    <div className="flex flex-wrap items-end gap-3">
                        {active === 'rent-roll' && periods.length > 0 && (
                            <label className="text-sm">
                                <span className="mb-1 block font-medium text-gray-700">Month</span>
                                <select
                                    value={options.period ?? periods[0].value}
                                    onChange={(e) => go(active, { period: e.target.value })}
                                    className="min-h-touch rounded-md border-gray-300 text-base"
                                >
                                    {periods.map((p) => (
                                        <option key={p.value} value={p.value}>
                                            {p.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}

                        {active === 'payments' && (
                            <label className="text-sm">
                                <span className="mb-1 block font-medium text-gray-700">Status</span>
                                <select
                                    value={options.status ?? ''}
                                    onChange={(e) => go(active, { status: e.target.value })}
                                    className="min-h-touch rounded-md border-gray-300 text-base"
                                >
                                    <option value="">Any status</option>
                                    {paymentStatuses.map((s) => (
                                        <option key={s} value={s}>
                                            {s}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}

                        <div className="flex gap-2">
                            {/* Plain links, not Inertia — a file download must
                                leave the SPA rather than be fetched into it. */}
                            <a
                                href={exportHref('csv')}
                                className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-3 text-base font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            >
                                Spreadsheet
                            </a>
                            <a
                                href={exportHref('pdf')}
                                className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-3 text-base font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            >
                                PDF
                            </a>
                        </div>
                    </div>
                </div>

                {report.rows.length === 0 ? (
                    <div className="p-4">
                        <EmptyState
                            title="Nothing to report for this selection"
                            description="No ledger activity matched. Try another month, or clear the filter."
                        />
                    </div>
                ) : (
                    // Wide tables scroll in their own container so the page
                    // never moves sideways (UI §6).
                    <div className="overflow-x-auto">
                        <table className="w-full text-base">
                            <thead>
                                <tr className="border-b border-gray-300">
                                    {report.columns.map((column) => (
                                        <th
                                            key={column.key}
                                            scope="col"
                                            className={`whitespace-nowrap px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-600 ${
                                                column.money ? 'text-right' : 'text-left'
                                            }`}
                                        >
                                            {column.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-gray-200">
                                {report.rows.map((row, index) => (
                                    <tr key={index} className="hover:bg-gray-50">
                                        {report.columns.map((column) => (
                                            <td
                                                key={column.key}
                                                className={`whitespace-nowrap px-3 py-2 ${
                                                    column.money ? 'text-right tabular-nums' : ''
                                                }`}
                                            >
                                                {column.money ? (
                                                    <Money value={row[column.key]} balance={column.balance} />
                                                ) : (
                                                    row[column.key]
                                                )}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>

                            {Object.keys(report.totals).length > 0 && (
                                <tfoot>
                                    <tr className="border-t-2 border-gray-900 font-semibold">
                                        {report.columns.map((column) => {
                                            const value = report.totals[column.key];

                                            return (
                                                <td
                                                    key={column.key}
                                                    className={`whitespace-nowrap px-3 py-2 ${
                                                        column.money ? 'text-right tabular-nums' : ''
                                                    }`}
                                                >
                                                    {column.money && value ? (
                                                        <Money value={value} balance={column.balance} />
                                                    ) : (
                                                        value
                                                    )}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                )}

                {report.notes.length > 0 && (
                    <div className="border-t border-gray-200 p-4">
                        <ul className="max-w-prose space-y-1 text-sm text-gray-600">
                            {report.notes.map((note, index) => (
                                <li key={index}>{note}</li>
                            ))}
                        </ul>
                    </div>
                )}
            </section>

            <p className="mt-4 max-w-prose text-sm text-gray-600">
                Every figure here is worked out from the ledger the moment you open the report —
                nothing is cached. If a total looks wrong, the{' '}
                <Link
                    href="/admin/ledger"
                    className="font-medium text-brand-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    ledger
                </Link>{' '}
                is the thing to check it against, and the two cannot disagree.
            </p>
        </AdminLayout>
    );
}
