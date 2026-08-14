import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import Alert from '@/Components/Alert';

/** Properties list.  [FR-REG-01, UI §3.10] */
export default function Index({ properties, filters = {}, flash = {} }) {
    const [search, setSearch] = useState(filters.search ?? '');

    const submitSearch = (e) => {
        e.preventDefault();
        // Server-side filtering, not client-side: the portfolio is 25 today but
        // the list must not depend on shipping every row to the browser.
        router.get('/admin/properties', { search }, { preserveState: true, replace: true });
    };

    const columns = [
        {
            key: 'name',
            header: 'Property',
            render: (row) => (
                <Link
                    href={`/admin/properties/${row.id}`}
                    className="font-medium underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    {row.name}
                </Link>
            ),
        },
        {
            key: 'address',
            header: 'Address',
            render: (row) => `${row.street_address}, ${row.city}, ${row.state} ${row.zip}`,
        },
        { key: 'county', header: 'County', hideOnMobile: true, render: (row) => row.county ?? '—' },
        {
            key: 'units',
            header: 'Units',
            align: 'right',
            render: (row) =>
                row.units_count === 0 ? (
                    <span className="text-gray-600">None</span>
                ) : (
                    // Spelled out rather than two adjacent numerals: "1" beside
                    // "1 occupied" renders as "11 occupied" and reads as eleven.
                    <span className="tabular-nums">
                        {row.units_count} {row.units_count === 1 ? 'unit' : 'units'}
                        <span className="text-gray-600">
                            {' · '}
                            {row.occupied_units_count} occupied, {row.vacant_units_count} vacant
                        </span>
                    </span>
                ),
        },
    ];

    return (
        <AdminLayout header="Properties">
            <Head title="Properties" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <form onSubmit={submitSearch} className="flex gap-2">
                    <label htmlFor="property-search" className="sr-only">
                        Search properties by name, address, city or ZIP
                    </label>
                    <input
                        id="property-search"
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Name, address, city or ZIP"
                        className="min-h-touch w-full rounded-md border-gray-300 text-base sm:w-72"
                    />
                    <button
                        type="submit"
                        className="min-h-touch rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Search
                    </button>
                </form>

                <Link
                    href="/admin/properties/create"
                    className="inline-flex min-h-touch items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    Add property
                </Link>
            </div>

            <DataTable
                columns={columns}
                rows={properties.data}
                caption="Properties"
                empty={
                    <EmptyState
                        title={filters.search ? 'No properties match that search.' : 'No properties yet.'}
                        description={
                            filters.search
                                ? 'Try a different name, city or ZIP.'
                                : 'Add your first property to begin building the portfolio.'
                        }
                    />
                }
            />

            {properties.last_page > 1 && (
                <nav aria-label="Pagination" className="mt-4 flex flex-wrap gap-1">
                    {properties.links.map((link, i) => (
                        <Link
                            key={i}
                            href={link.url ?? '#'}
                            aria-current={link.active ? 'page' : undefined}
                            aria-disabled={!link.url}
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
