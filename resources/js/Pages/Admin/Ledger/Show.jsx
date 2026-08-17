import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import Alert from '@/Components/Alert';
import FormField from '@/Components/FormField';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Money from '@/Components/Money';

/**
 * Tenant ledger.  [UI §3.8, WP-09 + WP-12]
 *
 * Both payers shown side by side and interleaved — legitimate here, and only
 * here (§5.3). The tenant portal never receives the Housing Authority figure
 * at all (I-4).
 *
 * The two balances are never added together: they are two separate obligations
 * (BR-01), and a combined total would answer a question nobody asks.
 */
export default function Show({ tenant, hasActiveLease, balances, entries, flash = {}, errors = {} }) {
    const [reversing, setReversing] = useState(null);
    const [adjusting, setAdjusting] = useState(false);

    const reversal = useForm({ reason: '' });
    const adjustment = useForm({
        payer: 'tenant',
        amount: '',
        reason: '',
        description: '',
    });

    const columns = [
        { key: 'posted_on', header: 'Date' },
        {
            key: 'description',
            header: 'Description',
            render: (e) => (
                <span>
                    {e.description}
                    {e.reason && <span className="block text-sm text-gray-600">{e.reason}</span>}
                    {/* AC-LED-08: the original is never hidden. It is labelled
                        so the pair reads as a correction rather than a mystery. */}
                    {e.is_reversed && (
                        <span className="block text-sm font-medium text-overdue-fg">Reversed</span>
                    )}
                    {e.reverses_entry_id && (
                        <span className="block text-sm text-gray-600">
                            Reverses entry #{e.reverses_entry_id}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'payer',
            header: 'Payer',
            // Labelled, not just colour-coded (UI §9).
            render: (e) => (
                <StatusBadge
                    status={e.payer}
                    label={e.payer === 'tenant' ? 'Tenant' : 'Housing authority'}
                    tone={e.payer === 'tenant' ? 'neutral' : 'settled'}
                />
            ),
        },
        { key: 'amount', header: 'Amount', align: 'right', render: (e) => <Money value={e.amount} /> },
        {
            key: 'running',
            header: 'Balance',
            align: 'right',
            render: (e) =>
                e.counts ? (
                    <Money value={e.running} />
                ) : (
                    // BR-05: shown, but it has not moved the balance.
                    <span className="text-sm text-gray-600">—</span>
                ),
        },
        { key: 'status', header: 'Status', align: 'right', render: (e) => <StatusBadge status={e.status} /> },
        {
            key: 'actions',
            header: '',
            align: 'right',
            render: (e) =>
                e.can_reverse ? (
                    <button
                        type="button"
                        onClick={() => setReversing(e)}
                        className="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Reverse
                    </button>
                ) : null,
        },
    ];

    return (
        <AdminLayout header={`Ledger — ${tenant.name}`}>
            <Head title={`Ledger — ${tenant.name}`} />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <div className="rounded-lg border border-gray-200 bg-white p-4">
                    <p className="text-sm text-gray-600">Tenant balance</p>
                    <p className="mt-1 text-2xl">
                        <Money value={balances.tenant} balance />
                    </p>
                    {balances.pending !== '0.00' && (
                        <p className="mt-1 text-sm text-gray-600">
                            <Money value={balances.pending} /> processing
                        </p>
                    )}
                </div>

                <div className="rounded-lg border border-gray-200 bg-white p-4">
                    <p className="text-sm text-gray-600">Housing authority balance</p>
                    <p className="mt-1 text-2xl">
                        <Money value={balances.ha} />
                    </p>
                    <p className="mt-1 text-sm text-gray-600">Never shown to the resident.</p>
                </div>

                <div className="rounded-lg border border-gray-200 bg-white p-4">
                    <p className="text-sm text-gray-600">Property</p>
                    <p className="mt-1 text-base">
                        {tenant.property ? `${tenant.property} — unit ${tenant.unit}` : 'No active lease'}
                    </p>
                    <Link
                        href={`/admin/tenants/${tenant.id}`}
                        className="mt-2 inline-block text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Tenant record
                    </Link>
                </div>
            </div>

            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-lg font-semibold text-gray-900">Transactions</h2>
                {hasActiveLease && (
                    <button
                        type="button"
                        onClick={() => setAdjusting(true)}
                        className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Post adjustment
                    </button>
                )}
            </div>

            <DataTable
                columns={columns}
                rows={entries}
                caption={`Ledger for ${tenant.name}`}
                rowKey={(e) => e.id}
                empty={
                    <EmptyState
                        title="No transactions yet."
                        description="Charges appear here once rent posts for the period."
                    />
                }
            />

            <ConfirmDialog
                open={adjusting}
                title="Post an adjustment"
                confirmLabel="Post adjustment"
                processing={adjustment.processing}
                onCancel={() => setAdjusting(false)}
                onConfirm={() =>
                    adjustment.post(`/admin/ledger/${tenant.id}/adjustments`, {
                        onSuccess: () => {
                            setAdjusting(false);
                            adjustment.reset();
                        },
                    })
                }
            >
                <p className="mb-3">
                    This posts a permanent entry. It cannot be edited afterwards — a mistake is
                    corrected with a reversal, which also stays on the record.
                </p>

                <FormField label="Applies to" error={adjustment.errors.payer} required>
                    <select
                        value={adjustment.data.payer}
                        onChange={(e) => adjustment.setData('payer', e.target.value)}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        <option value="tenant">Tenant</option>
                        <option value="housing_authority">Housing authority</option>
                    </select>
                </FormField>

                <FormField
                    label="Amount"
                    value={adjustment.data.amount}
                    onChange={(e) => adjustment.setData('amount', e.target.value)}
                    error={adjustment.errors.amount}
                    inputMode="decimal"
                    hint="Positive adds to the balance, negative credits it."
                    required
                />

                <FormField
                    label="Description"
                    value={adjustment.data.description}
                    onChange={(e) => adjustment.setData('description', e.target.value)}
                    error={adjustment.errors.description}
                    maxLength={255}
                    required
                />

                <FormField
                    label="Reason"
                    value={adjustment.data.reason}
                    onChange={(e) => adjustment.setData('reason', e.target.value)}
                    error={adjustment.errors.reason}
                    maxLength={500}
                    hint="Why this adjustment is being made. Becomes part of the permanent record."
                    required
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={reversing !== null}
                title="Reverse this entry?"
                confirmLabel="Post reversal"
                tone="danger"
                processing={reversal.processing}
                onCancel={() => setReversing(null)}
                onConfirm={() =>
                    reversal.post(`/admin/ledger/${tenant.id}/entries/${reversing?.id}/reverse`, {
                        onSuccess: () => {
                            setReversing(null);
                            reversal.reset();
                        },
                    })
                }
            >
                <p className="mb-3">
                    This posts an opposing entry. Both the original and the reversal stay visible on
                    the ledger — nothing is deleted or hidden.
                </p>

                <FormField
                    label="Reason"
                    value={reversal.data.reason}
                    onChange={(e) => reversal.setData('reason', e.target.value)}
                    error={reversal.errors.reason}
                    maxLength={500}
                    required
                />
            </ConfirmDialog>
        </AdminLayout>
    );
}
