import { useMemo } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import FormField from '@/Components/FormField';
import Alert from '@/Components/Alert';
import Money from '@/Components/Money';

/**
 * Record an offline payment.  [UI §3.9, §4.4, FR-PAY-05, API-ADM-15]
 *
 * Cheque, money order, cash, bank transfer, Housing Authority remittance.
 *
 * Nothing on this screen is disabled by a tenant's delinquency state. An admin
 * recording a cheque for an account in Management Review is the normal case,
 * not an exception (BR-12, AC-PAY-15) — so the state is shown as context and
 * never as a blocker.
 */

const METHODS = [
    { value: 'cheque', label: 'Cheque' },
    { value: 'money_order', label: 'Money order' },
    { value: 'cash', label: 'Cash' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'ha_remittance', label: 'Housing authority remittance' },
];

const REFERENCE_HINTS = {
    cheque: 'Cheque number. Needed to match this against a bank statement later.',
    money_order: 'Money order number.',
    bank_transfer: 'Transfer reference, if the bank gave one.',
    ha_remittance: 'Remittance advice number.',
    cash: 'Receipt number, if one was issued.',
};

export default function Record({ leases, preselectedLeaseId, today, idempotencyKey, flash = {} }) {
    const { data, setData, post, processing, errors } = useForm({
        lease_id: preselectedLeaseId ?? '',
        payer: 'tenant',
        amount: '',
        received_on: today,
        method: 'cheque',
        reference: '',
        note: '',
        idempotency_key: idempotencyKey,
    });

    const lease = useMemo(
        () => leases.find((l) => String(l.id) === String(data.lease_id)),
        [leases, data.lease_id],
    );

    const referenceRequired = data.method === 'cheque' || data.method === 'money_order';

    const submit = (e) => {
        e.preventDefault();
        post('/admin/payments/record');
    };

    return (
        <AdminLayout header="Record a payment">
            <Head title="Record a payment" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            <div className="mb-4">
                <Link
                    href="/admin/payments/remittance"
                    className="text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    One housing authority cheque covering several tenants?
                </Link>
            </div>

            <form onSubmit={submit} noValidate className="max-w-2xl">
                <FormField label="Lease" error={errors.lease_id} required>
                    <select
                        value={data.lease_id}
                        onChange={(e) => setData('lease_id', e.target.value)}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        <option value="">Choose a tenant…</option>
                        {leases.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.label}
                            </option>
                        ))}
                    </select>
                </FormField>

                {lease?.in_management_review && (
                    <Alert tone="info" className="mb-4" title="This account is in Management Review">
                        Recording a payment here is allowed and will reduce the balance. Only the
                        tenant’s own online payment is suspended.
                    </Alert>
                )}

                <FormField label="Paid by" error={errors.payer} required>
                    <select
                        value={data.payer}
                        onChange={(e) => setData('payer', e.target.value)}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        <option value="tenant">Tenant</option>
                        <option value="housing_authority" disabled={lease && !lease.has_authority}>
                            Housing authority
                        </option>
                    </select>
                </FormField>

                {/* Two separate obligations (BR-01). Naming which one this
                    payment reduces removes the most likely data-entry error. */}
                {lease && (
                    <p className="-mt-2 mb-4 text-sm text-gray-600">
                        {data.payer === 'tenant' ? (
                            <>
                                Reduces the tenant balance. Monthly tenant portion{' '}
                                <Money value={lease.tenant_portion} />.
                            </>
                        ) : (
                            <>
                                Reduces the housing authority balance only — the tenant balance is
                                unchanged. Monthly authority portion <Money value={lease.ha_portion} />.
                            </>
                        )}
                    </p>
                )}

                <div className="grid gap-x-4 sm:grid-cols-2">
                    <FormField
                        label="Amount received"
                        value={data.amount}
                        onChange={(e) => setData('amount', e.target.value)}
                        error={errors.amount}
                        inputMode="decimal"
                        placeholder="0.00"
                        required
                    />

                    <FormField
                        label="Date received"
                        type="date"
                        value={data.received_on}
                        onChange={(e) => setData('received_on', e.target.value)}
                        error={errors.received_on}
                        max={today}
                        hint="The day the money arrived, not today."
                        required
                    />
                </div>

                <FormField label="Method" error={errors.method} required>
                    <select
                        value={data.method}
                        onChange={(e) => setData('method', e.target.value)}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        {METHODS.map((m) => (
                            <option key={m.value} value={m.value}>
                                {m.label}
                            </option>
                        ))}
                    </select>
                </FormField>

                <FormField
                    label="Reference"
                    value={data.reference}
                    onChange={(e) => setData('reference', e.target.value)}
                    error={errors.reference}
                    hint={REFERENCE_HINTS[data.method]}
                    maxLength={100}
                    required={referenceRequired}
                />

                <FormField
                    label="Note"
                    value={data.note}
                    onChange={(e) => setData('note', e.target.value)}
                    error={errors.note}
                    hint="Optional. Appears on the ledger row, so write it for whoever reads this in a year."
                    maxLength={500}
                />

                <p className="mb-4 text-sm text-gray-600">
                    This posts immediately and cannot be edited afterwards. A mistake is corrected
                    with a reversal, which also stays on the record.
                </p>

                <div className="flex gap-3">
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {processing ? 'Recording…' : 'Record payment'}
                    </button>

                    <Link
                        href="/admin/payments"
                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </AdminLayout>
    );
}
