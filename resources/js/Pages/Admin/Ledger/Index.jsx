import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import Money from '@/Components/Money';

/** Ledger browser — every tenant, both balances.  [UI §3.8] */
export default function Index({ tenants }) {
    const columns = [
        {
            key: 'name',
            header: 'Tenant',
            render: (t) => (
                <Link
                    href={`/admin/ledger/${t.id}`}
                    className="font-medium underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    {t.name}
                </Link>
            ),
        },
        { key: 'unit', header: 'Property', hideOnMobile: true, render: (t) => t.unit ?? '—' },
        {
            key: 'tenant_balance',
            header: 'Tenant balance',
            align: 'right',
            // `balance` so a credit reads "Credit $X" rather than a minus figure.
            render: (t) => <Money value={t.tenant_balance} balance />,
        },
        {
            // Two obligations, never summed (BR-01).
            key: 'ha_balance',
            header: 'HA balance',
            align: 'right',
            render: (t) => <Money value={t.ha_balance} />,
        },
    ];

    return (
        <AdminLayout header="Ledger">
            <Head title="Ledger" />

            <DataTable
                columns={columns}
                rows={tenants}
                caption="Tenant balances"
                empty={<EmptyState title="No tenants yet." description="Add a tenant and a lease to begin." />}
            />
        </AdminLayout>
    );
}
