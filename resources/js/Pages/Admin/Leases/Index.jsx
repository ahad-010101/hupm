import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import Alert from '@/Components/Alert';
import Money from '@/Components/Money';

/** Leases list.  [FR-REG-02] */
export default function Index({ leases, filters = {}, flash = {} }) {
    const setStatus = (status) =>
        router.get('/admin/leases', { ...filters, status: status || undefined }, {
            preserveState: true,
            replace: true,
        });

    const columns = [
        {
            key: 'tenant',
            header: 'Tenant',
            render: (l) => (
                <Link
                    href={`/admin/leases/${l.id}/edit`}
                    className="font-medium underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    {l.tenant}
                </Link>
            ),
        },
        { key: 'unit', header: 'Unit', render: (l) => `${l.property} — ${l.unit}` },
        { key: 'term', header: 'Term', hideOnMobile: true, render: (l) => `${l.start_date} to ${l.end_date}` },
        {
            key: 'tenant_portion',
            header: 'Tenant portion',
            align: 'right',
            render: (l) => (
                <span>
                    <Money value={l.tenant_portion} />
                    {l.is_subsidised && (
                        <span className="block text-sm text-gray-600">
                            of <Money value={l.total_contract_rent} />
                        </span>
                    )}
                </span>
            ),
        },
        { key: 'status', header: 'Status', align: 'right', render: (l) => <StatusBadge status={l.status} /> },
    ];

    return (
        <AdminLayout header="Leases">
            <Head title="Leases" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-wrap gap-2">
                    {[
                        ['', 'All'],
                        ['active', 'Active'],
                        ['draft', 'Draft'],
                        ['ended', 'Ended'],
                    ].map(([value, label]) => (
                        <button
                            key={label}
                            type="button"
                            onClick={() => setStatus(value)}
                            aria-pressed={(filters.status ?? '') === value}
                            className={`min-h-touch rounded-md border px-4 text-base focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                                (filters.status ?? '') === value
                                    ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700'
                                    : 'border-gray-300 bg-white text-gray-700'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                <Link
                    href="/admin/leases/create"
                    className="inline-flex min-h-touch items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    New lease
                </Link>
            </div>

            <DataTable
                columns={columns}
                rows={leases.data}
                caption="Leases"
                empty={<EmptyState title="No leases match." description="Try a different status filter." />}
            />

            {leases.last_page > 1 && (
                <nav aria-label="Pagination" className="mt-4 flex flex-wrap gap-1">
                    {leases.links.map((link, i) => (
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
