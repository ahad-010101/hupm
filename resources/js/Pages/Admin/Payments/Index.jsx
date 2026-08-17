import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import Alert from '@/Components/Alert';
import Money from '@/Components/Money';

/**
 * Payments.  [UI §3.9, API-ADM-14]
 *
 * Reconciliation status and the 36-hour staleness banner arrive with WP-14,
 * which is where the gateway does. This is the list and the two ways to record
 * money that came in by hand.
 */

const METHOD_LABELS = {
    cheque: 'Cheque',
    money_order: 'Money order',
    cash: 'Cash',
    bank_transfer: 'Bank transfer',
    ha_remittance: 'HA remittance',
    echeck: 'eCheck',
    card: 'Card',
};

const TABS = [
    { value: '', label: 'All' },
    { value: 'pending', label: 'Pending' },
    { value: 'settled', label: 'Settled' },
    { value: 'returned', label: 'Returned' },
];

export default function Index({ payments, filters = {}, flash = {} }) {
    const setStatus = (status) => {
        router.get('/admin/payments', status ? { status } : {}, { preserveScroll: true });
    };

    const columns = [
        { key: 'received_on', header: 'Date' },
        {
            key: 'tenant',
            header: 'Tenant',
            render: (p) => (
                <Link
                    href={`/admin/ledger/${p.tenant_id}`}
                    className="font-medium underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    {p.tenant}
                </Link>
            ),
        },
        {
            key: 'payer',
            header: 'Payer',
            // Labelled, never colour alone (UI §9).
            render: (p) => (
                <StatusBadge
                    status={p.payer}
                    label={p.payer === 'tenant' ? 'Tenant' : 'Housing authority'}
                    tone={p.payer === 'tenant' ? 'neutral' : 'settled'}
                />
            ),
        },
        {
            key: 'method',
            header: 'Method',
            hideOnMobile: true,
            render: (p) => (
                <span>
                    {METHOD_LABELS[p.method] ?? p.method}
                    {p.reference && <span className="block text-sm text-gray-600">{p.reference}</span>}
                    {p.batch_id && (
                        <Link
                            href={`/admin/payments?batch=${encodeURIComponent(p.batch_id)}`}
                            className="block text-sm text-gray-600 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            Batch {p.batch_id}
                        </Link>
                    )}
                </span>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            render: (p) => (
                <span>
                    <Money value={p.amount} />
                    {p.unapplied !== '0.00' && (
                        <span className="block text-sm text-gray-600">
                            <Money value={p.unapplied} /> unapplied
                        </span>
                    )}
                </span>
            ),
        },
        { key: 'status', header: 'Status', align: 'right', render: (p) => <StatusBadge status={p.status} /> },
    ];

    return (
        <AdminLayout header="Payments">
            <Head title="Payments" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            {filters.batch && (
                <Alert tone="info" className="mb-4" title="Showing one remittance batch">
                    {filters.batch} —{' '}
                    <Link href="/admin/payments" className="underline">
                        show all payments
                    </Link>
                </Alert>
            )}

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-wrap gap-1" role="group" aria-label="Filter by status">
                    {TABS.map((tab) => {
                        const active = (filters.status ?? '') === tab.value;

                        return (
                            <button
                                key={tab.value || 'all'}
                                type="button"
                                onClick={() => setStatus(tab.value)}
                                aria-pressed={active}
                                className={`inline-flex min-h-touch items-center rounded-md border px-3 text-base focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 ${
                                    active
                                        ? 'border-brand-600 bg-brand-50 font-semibold text-brand-700'
                                        : 'border-gray-300 bg-white text-gray-700'
                                }`}
                            >
                                {tab.label}
                            </button>
                        );
                    })}
                </div>

                <div className="flex flex-wrap gap-2">
                    <Link
                        href="/admin/payments/remittance"
                        className="inline-flex min-h-touch items-center justify-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Record HA remittance
                    </Link>
                    <Link
                        href="/admin/payments/record"
                        className="inline-flex min-h-touch items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Record payment
                    </Link>
                </div>
            </div>

            <DataTable
                columns={columns}
                rows={payments.data}
                caption="Payments"
                empty={
                    <EmptyState
                        title="No payments yet."
                        description="Record a cheque, money order or housing authority remittance to begin."
                    />
                }
            />

            {payments.last_page > 1 && (
                <nav aria-label="Pagination" className="mt-4 flex flex-wrap gap-1">
                    {payments.links.map((link, i) => (
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
