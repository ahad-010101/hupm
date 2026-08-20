import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/Components/EmptyState';

/**
 * Global search results.  [FR-ADM-01]
 *
 * Residents, units and ticket numbers — the three things somebody reads out on
 * the phone. Each result is a link to the screen it opens, because search here
 * is a way of getting somewhere, not a report about matches.
 */

function Group({ title, items, empty, secondary }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <section className="rounded-lg border border-gray-200 bg-white">
            <h2 className="border-b border-gray-200 px-4 py-3 text-base font-semibold text-gray-900">
                {title} <span className="font-normal text-gray-500">({items.length})</span>
            </h2>
            <ul className="divide-y divide-gray-200">
                {items.map((item) => (
                    <li key={item.id} className="flex items-center justify-between gap-3 px-4 py-3">
                        <div className="min-w-0">
                            <Link
                                href={item.href}
                                className="text-base font-medium text-brand-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            >
                                {item.name}
                            </Link>
                            <p className="truncate text-base text-gray-600">{item.detail}</p>
                        </div>
                        {secondary && item[secondary.key] && (
                            <Link
                                href={item[secondary.key]}
                                className="min-h-touch shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            >
                                {secondary.label}
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
            {empty}
        </section>
    );
}

export default function Search({ results }) {
    const { term, total, tenants, units, tickets } = results;

    return (
        <AdminLayout header={term ? `Search: ${term}` : 'Search'}>
            <Head title={term ? `Search: ${term}` : 'Search'} />

            {total === 0 ? (
                <EmptyState
                    title={term.length < 2 ? 'Type at least two characters' : `Nothing matches “${term}”`}
                    description="Search looks at resident names, email, phone, unit numbers and ticket numbers."
                />
            ) : (
                <div className="space-y-4">
                    <Group
                        title="Residents"
                        items={tenants}
                        secondary={{ key: 'ledger_href', label: 'Ledger' }}
                    />
                    <Group title="Units" items={units} />
                    <Group title="Maintenance tickets" items={tickets} />
                </div>
            )}
        </AdminLayout>
    );
}
