import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
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

export default function Index({
    payments,
    filters = {},
    reconciliation = {},
    unmatchedCount = 0,
    flash = {},
}) {
    const rerun = useForm({});

    // [WP-48] The one payment outcome a person has to enter. A card chargeback
    // is settled between the issuer and the acquirer, so the gateway never
    // reports it — the acquirer's letter arrives in the post and this is where
    // it becomes a ledger movement.
    //
    // An inline panel rather than a dialog: it asks for typed input, and a
    // modal that steals focus on every keystroke is a bug this project has
    // already had once.
    const [chargebackFor, setChargebackFor] = useState(null);
    const chargeback = useForm({ reason: '', code: '' });

    const submitChargeback = (event) => {
        event.preventDefault();

        chargeback.post(`/admin/payments/${chargebackFor.id}/chargeback`, {
            preserveScroll: true,
            onSuccess: () => {
                chargeback.reset();
                setChargebackFor(null);
            },
        });
    };

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
        {
            key: 'status',
            header: 'Status',
            align: 'right',
            render: (p) => (
                <span>
                    <StatusBadge status={p.status} />
                    {p.return_code && (
                        // The bank's code and its words. "Returned" alone is not
                        // something anyone can act on.
                        <span className="block text-sm text-gray-600">
                            {p.return_code} — {p.return_description}
                        </span>
                    )}
                    {p.flagged && (
                        <span className="block text-sm text-gray-600">Awaiting reconciliation</span>
                    )}
                    {p.status === 'settled' && (
                        <button
                            type="button"
                            onClick={() => setChargebackFor(p)}
                            className="mt-1 block text-sm font-medium text-brand-700 underline hover:text-brand-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            {p.method === 'card' ? 'Record a chargeback' : 'Record a return'}
                        </button>
                    )}
                </span>
            ),
        },
    ];

    return (
        <AdminLayout header="Payments">
            <Head title="Payments" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            {chargebackFor && (
                <form
                    onSubmit={submitChargeback}
                    className="mb-4 rounded-lg border border-overdue-border bg-white p-4"
                >
                    <h2 className="text-base font-semibold text-gray-900">
                        {chargebackFor.method === 'card' ? 'Record a chargeback' : 'Record a return'}
                    </h2>
                    <p className="mt-1 max-w-prose text-base text-gray-700">
                        {chargebackFor.tenant} — <Money value={chargebackFor.amount} /> taken on{' '}
                        {chargebackFor.received_on}.
                    </p>
                    <p className="mt-2 max-w-prose text-base text-gray-600">
                        This puts the money back on their account: the payment stops counting, the
                        charges it covered are outstanding again, and any fee charged for making it
                        goes back with it. The resident is emailed the reason you give below.
                        {chargebackFor.method === 'card' && (
                            <> No returned-payment fee is charged automatically on a card — that is
                            a decision for you, on the ledger.</>
                        )}
                    </p>

                    <div className="mt-3 flex flex-wrap gap-3">
                        <div className="min-w-0 flex-1">
                            <label htmlFor="cb-reason" className="block text-base font-medium text-gray-900">
                                Why it came back
                            </label>
                            <input
                                id="cb-reason"
                                type="text"
                                value={chargeback.data.reason}
                                onChange={(e) => chargeback.setData('reason', e.target.value)}
                                placeholder="Cardholder disputed the charge as unrecognised"
                                className="mt-1 min-h-touch w-full rounded-md border-gray-300 text-base"
                                required
                            />
                            {chargeback.errors.reason && (
                                <p className="mt-1 text-base text-overdue-fg">{chargeback.errors.reason}</p>
                            )}
                        </div>
                        <div>
                            <label htmlFor="cb-code" className="block text-base font-medium text-gray-900">
                                Code <span className="font-normal text-gray-600">(optional)</span>
                            </label>
                            <input
                                id="cb-code"
                                type="text"
                                value={chargeback.data.code}
                                onChange={(e) => chargeback.setData('code', e.target.value)}
                                placeholder="4853"
                                className="mt-1 min-h-touch w-32 rounded-md border-gray-300 text-base"
                            />
                            {chargeback.errors.code && (
                                <p className="mt-1 text-base text-overdue-fg">{chargeback.errors.code}</p>
                            )}
                        </div>
                    </div>

                    <div className="mt-3 flex flex-wrap gap-2">
                        <button
                            type="submit"
                            disabled={chargeback.processing}
                            className="min-h-touch rounded-md bg-overdue-fg px-4 text-base font-semibold text-white hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-50"
                        >
                            {chargeback.processing ? 'Recording…' : 'Record it'}
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                chargeback.reset();
                                chargeback.clearErrors();
                                setChargebackFor(null);
                            }}
                            className="min-h-touch rounded-md border border-gray-300 px-3 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            )}
            {flash.error && <Alert tone="error" className="mb-4">{flash.error}</Alert>}

            {/* UI §3.9: the last successful reconciliation must be visible at
                all times, not only when it is bad news. A figure you have been
                reading all month is one you notice going wrong. */}
            <div className="mb-4 flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p className="text-sm text-gray-600">Last successful reconciliation</p>
                    <p className="mt-1 text-base font-medium text-gray-900">
                        {reconciliation.never_run
                            ? 'Never run'
                            : `${reconciliation.hours_ago} hours ago`}
                        {reconciliation.stale && (
                            <span className="ml-2 font-semibold text-overdue-fg">
                                — overdue, settled payments are not clearing
                            </span>
                        )}
                    </p>
                </div>

                <button
                    type="button"
                    disabled={rerun.processing}
                    onClick={() => rerun.post('/admin/payments/reconcile', { preserveScroll: true })}
                    className="inline-flex min-h-touch items-center justify-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                >
                    {rerun.processing ? 'Reconciling…' : 'Reconcile now'}
                </button>
            </div>

            {unmatchedCount > 0 && !filters.unmatched && (
                <Alert tone="warning" className="mb-4" title="Payments needing review">
                    {unmatchedCount} payment{unmatchedCount === 1 ? ' has' : 's have'} been pending
                    beyond the reconciliation window. Nothing is voided automatically —{' '}
                    <Link href="/admin/payments?unmatched=1" className="underline">
                        review {unmatchedCount === 1 ? 'it' : 'them'}
                    </Link>
                    .
                </Alert>
            )}

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
