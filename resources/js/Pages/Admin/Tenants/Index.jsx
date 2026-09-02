import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import Alert from '@/Components/Alert';

/** Tenants list.  [FR-REG-02, Q-4] */
export default function Index({ tenants, filters = {}, sort, flash = {} }) {
    const [search, setSearch] = useState(filters.search ?? '');

    // One reload for search, status and sort, so none of the three can drop
    // another's parameter.
    const reload = (params = {}) =>
        router.get(
            '/admin/tenants',
            { search, status: filters.status, sort: sort?.key, direction: sort?.direction, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const submit = (e) => {
        e.preventDefault();
        reload();
    };

    const columns = [
        {
            key: 'name',
            header: 'Tenant',
            sortable: true,
            render: (t) => (
                <Link
                    href={`/admin/tenants/${t.id}`}
                    className="font-medium underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    {t.name}
                </Link>
            ),
        },
        {
            key: 'contact',
            header: 'Contact',
            // Sorts by email; a tenant with no address (Q-4) sorts last.
            sortable: true,
            sortKey: 'email',
            render: (t) =>
                t.contactable ? (
                    <span>
                        {t.email}
                        {t.phone && <span className="block text-sm text-gray-600">{t.phone}</span>}
                    </span>
                ) : (
                    // Q-4: no address means no portal account and no email can
                    // ever reach them. Admin needs to see that here, not
                    // discover it when a notice silently goes nowhere.
                    <span>
                        <StatusBadge status="not_deliverable" />
                        {t.phone && <span className="block text-sm text-gray-600">{t.phone}</span>}
                    </span>
                ),
        },
        { key: 'unit', header: 'Property', hideOnMobile: true, render: (t) => t.unit ?? '—' },
        {
            key: 'account_status',
            header: 'Portal',
            render: (t) =>
                t.account_status ? (
                    <StatusBadge status={t.account_status} />
                ) : (
                    <span className="text-sm text-gray-600">No account</span>
                ),
        },
        {
            key: 'added',
            header: 'Added',
            hideOnMobile: true,
            sortable: true,
            sortKey: 'created_at',
            sortLabels: { desc: 'Added, newest first', asc: 'Added, oldest first' },
            render: (t) => t.created_at ?? '—',
        },
        { key: 'status', header: 'Status', align: 'right', sortable: true, render: (t) => <StatusBadge status={t.status} /> },
    ];

    return (
        <AdminLayout header="Tenants">
            <Head title="Tenants" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <form onSubmit={submit} className="flex gap-2">
                    <label htmlFor="tenant-search" className="sr-only">
                        Search tenants by name, email or phone
                    </label>
                    <input
                        id="tenant-search"
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Name, email or phone"
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
                    href="/admin/tenants/create"
                    className="inline-flex min-h-touch items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    Add tenant
                </Link>
            </div>

            <DataTable
                columns={columns}
                rows={tenants.data}
                caption="Tenants"
                sort={sort}
                onSort={(key, direction) => reload({ sort: key, direction })}
                empty={<EmptyState title="No tenants match." description="Try a different name, email or phone number." />}
            />

            {tenants.last_page > 1 && (
                <nav aria-label="Pagination" className="mt-4 flex flex-wrap gap-1">
                    {tenants.links.map((link, i) => (
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
