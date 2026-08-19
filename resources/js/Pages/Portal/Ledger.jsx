import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import EmptyState from '@/Components/EmptyState';
import Money from '@/Components/Money';

/**
 * Tenant ledger.  [UI §3.4, FR-POR-02, AC-POR-03]
 *
 * INVARIANT I-4 / AC-POR-03: only `payer = tenant` rows exist here. Not
 * filtered in this component — the controller's query never selects the
 * others, so there is nothing in the payload to leak.
 *
 * Mobile stacks each row as a card with its own running balance rather than
 * side-scrolling a table (UI §3.4, §6). A financial table that scrolls
 * sideways on a 375px screen hides the column that matters, which is the one
 * on the right.
 */
export default function Ledger({ entries = [], balance, pending, filters = {} }) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const hasPending = pending && pending !== '0.00';
    const filtered = Boolean(filters.from || filters.to);

    const applyFilter = (e) => {
        e.preventDefault();
        router.get('/portal/ledger', { from: from || undefined, to: to || undefined }, {
            preserveScroll: true,
        });
    };

    const exportUrl = `/portal/ledger/export${
        from || to ? `?${new URLSearchParams({ ...(from && { from }), ...(to && { to }) })}` : ''
    }`;

    return (
        <PortalLayout header="Your history" balance={balance} pendingAmount={hasPending ? pending : null}>
            <Head title="Your history" />

            <section className="mb-4 rounded-lg border border-gray-200 bg-white p-5">
                <h2 className="text-sm text-gray-600">Balance</h2>
                <p className="mt-1 text-3xl">
                    <Money value={balance} balance />
                </p>
                {hasPending && (
                    <p className="mt-2 text-base text-gray-700">
                        <Money value={pending} /> is processing and will come off once it clears
                        with your bank.
                    </p>
                )}
            </section>

            <form onSubmit={applyFilter} className="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label htmlFor="from" className="mb-1 block text-sm font-medium text-gray-900">
                        From
                    </label>
                    <input
                        id="from"
                        type="date"
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                        className="min-h-touch rounded-md border-gray-300 text-base focus:border-brand-600 focus:ring-brand-600"
                    />
                </div>
                <div>
                    <label htmlFor="to" className="mb-1 block text-sm font-medium text-gray-900">
                        To
                    </label>
                    <input
                        id="to"
                        type="date"
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                        className="min-h-touch rounded-md border-gray-300 text-base focus:border-brand-600 focus:ring-brand-600"
                    />
                </div>

                <button
                    type="submit"
                    className="min-h-touch rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    Apply
                </button>

                {filtered && (
                    <Link
                        href="/portal/ledger"
                        className="min-h-touch inline-flex items-center text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Clear
                    </Link>
                )}

                {/* A real link, not a fetch: the browser saves the file itself and
                    the tenant keeps a copy they can send on. */}
                <a
                    href={exportUrl}
                    className="min-h-touch ml-auto inline-flex items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    Download statement
                </a>
            </form>

            {entries.length === 0 ? (
                <EmptyState
                    title={filtered ? 'Nothing in those dates.' : 'No transactions yet.'}
                    description={
                        filtered
                            ? 'Try a wider date range.'
                            : 'Charges and payments appear here as they happen.'
                    }
                />
            ) : (
                <>
                    {/* Phone: stacked cards, each carrying its own balance. */}
                    <ul className="space-y-2 sm:hidden">
                        {entries.map((entry) => (
                            <li key={entry.id} className="rounded-lg border border-gray-200 bg-white p-4">
                                <div className="flex items-baseline justify-between gap-3">
                                    <span className="text-base font-medium text-gray-900">
                                        {entry.description}
                                    </span>
                                    <span className="shrink-0">
                                        {entry.charge && <Money value={entry.charge} />}
                                        {entry.payment && (
                                            <span className="text-credit-fg">
                                                −<Money value={entry.payment} />
                                            </span>
                                        )}
                                    </span>
                                </div>
                                <div className="mt-1 flex items-baseline justify-between gap-3 text-sm text-gray-600">
                                    <span>
                                        {entry.date}
                                        {entry.state && ` · ${entry.state}`}
                                    </span>
                                    <span>
                                        {entry.counts ? (
                                            <>Balance <Money value={entry.running} /></>
                                        ) : (
                                            'Balance unchanged'
                                        )}
                                    </span>
                                </div>
                            </li>
                        ))}
                    </ul>

                    {/* Desktop: a real table with real headers. */}
                    <div className="hidden overflow-x-auto sm:block">
                        <table className="min-w-full divide-y divide-gray-200">
                            <caption className="sr-only">Your charges and payments</caption>
                            <thead className="bg-gray-50">
                                <tr>
                                    <th scope="col" className="px-3 py-2 text-left text-sm font-semibold text-gray-900">Date</th>
                                    <th scope="col" className="px-3 py-2 text-left text-sm font-semibold text-gray-900">Description</th>
                                    <th scope="col" className="px-3 py-2 text-right text-sm font-semibold text-gray-900">Charge</th>
                                    <th scope="col" className="px-3 py-2 text-right text-sm font-semibold text-gray-900">Payment</th>
                                    <th scope="col" className="px-3 py-2 text-right text-sm font-semibold text-gray-900">Balance</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 bg-white">
                                {entries.map((entry) => (
                                    <tr key={entry.id}>
                                        <td className="whitespace-nowrap px-3 py-2 text-base">{entry.date}</td>
                                        <td className="px-3 py-2 text-base">
                                            {entry.description}
                                            {entry.state && (
                                                <span className="block text-sm text-gray-600">{entry.state}</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right text-base">
                                            {entry.charge ? <Money value={entry.charge} /> : ''}
                                        </td>
                                        <td className="px-3 py-2 text-right text-base">
                                            {entry.payment ? <Money value={entry.payment} /> : ''}
                                        </td>
                                        <td className="px-3 py-2 text-right text-base">
                                            {entry.counts ? (
                                                <Money value={entry.running} />
                                            ) : (
                                                <span className="text-sm text-gray-600">—</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </PortalLayout>
    );
}
