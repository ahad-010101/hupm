import { Head } from '@inertiajs/react';
import HaLayout from '@/Layouts/HaLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import Money from '@/Components/Money';
import StatusBadge from '@/Components/StatusBadge';

/**
 * The agency's own statement.  [WP-43, D-29]
 *
 * Every row here is `payer = housing_authority`. A resident's own charges,
 * payments and arrears are not on this page and cannot be — the query behind
 * it does not select them, the same way the resident ledger never selects the
 * agency's portion (I-4).
 */
export default function Ledger({ authority, entries = [], total }) {
    const columns = [
        { key: 'posted_on', header: 'Date' },
        {
            key: 'description',
            header: 'Description',
            render: (e) => (
                <span>
                    {e.description}
                    <span className="block text-sm text-gray-600">
                        {e.property} · {e.resident}
                    </span>
                </span>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            render: (e) => <Money value={e.amount} />,
        },
        {
            key: 'status',
            header: 'Status',
            render: (e) => <StatusBadge status={e.status} />,
        },
    ];

    return (
        <HaLayout authority={authority} total={total} header="Statement">
            <Head title="Statement" />

            <DataTable
                columns={columns}
                rows={entries}
                caption="Charges and payments for this authority"
                empty={
                    <EmptyState
                        title="Nothing yet."
                        description="Charges for the leases you fund will appear here."
                    />
                }
            />
        </HaLayout>
    );
}
