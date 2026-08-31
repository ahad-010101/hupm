import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import FormField from '@/Components/FormField';
import Alert from '@/Components/Alert';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Money, { formatMoney } from '@/Components/Money';

/**
 * Lease create/edit.  [FR-REG-02, FR-REG-03, AC-REG-03…06]
 *
 * The partial-payment settings live on this form rather than a screen of their
 * own (GAP-1, DB §C1) — they are terms of the tenancy and belong beside the
 * other terms.
 */
export default function Form({ lease, tenants, properties, authorities, preselectedTenantId, flash = {} }) {
    const editing = Boolean(lease);
    const [terminating, setTerminating] = useState(false);

    const { data, setData, post, patch, processing, errors } = useForm({
        unit_id: lease?.unit_id ?? '',
        tenant_id: lease?.tenant_id ?? preselectedTenantId ?? '',
        housing_authority_id: lease?.housing_authority_id ?? '',
        start_date: lease?.start_date ?? '',
        end_date: lease?.end_date ?? '',
        total_contract_rent: lease?.total_contract_rent ?? '',
        tenant_portion: lease?.tenant_portion ?? '',
        ha_portion: lease?.ha_portion ?? '0.00',
        rent_due_day: lease?.rent_due_day ?? 1,
        grace_period_days: lease?.grace_period_days ?? 5,
        late_fee_flat: lease?.late_fee_flat ?? '0.00',
        late_fee_daily: lease?.late_fee_daily ?? '0.00',
        late_fee_max: lease?.late_fee_max ?? '',
        returned_payment_fee: lease?.returned_payment_fee ?? '0.00',
        security_deposit: lease?.security_deposit ?? '0.00',
        is_subsidised: lease?.is_subsidised ?? false,
        hap_contract_number: lease?.hap_contract_number ?? '',
        utility_responsibility: lease?.utility_responsibility ?? '',
        partial_payment_policy: lease?.partial_payment_policy ?? 'full_only',
        partial_minimum_amount: lease?.partial_minimum_amount ?? '',
        partial_policy_expires_on: lease?.partial_policy_expires_on ?? '',
        partial_requires_approval: lease?.partial_requires_approval ?? true,
        ledger_review_required: lease?.ledger_review_required ?? false,
        status: lease?.status ?? 'draft',
        lock_version: lease?.lock_version ?? '',
    });

    const terminate = useForm({ effective_date: '', reason: '' });

    const submit = (e) => {
        e.preventDefault();
        editing ? patch(`/admin/leases/${lease.id}`) : post('/admin/leases');
    };

    // Live arithmetic so the admin sees the AC-REG-03 rule before submitting
    // rather than after. Done on strings — never parseFloat (I-10).
    const portionsSum = (() => {
        try {
            const cents = (v) => formatMoney(v || '0').text.replace(/[$,]/g, '').replace('.', '');
            const tenant = parseInt(cents(data.tenant_portion), 10);
            const ha = parseInt(cents(data.ha_portion), 10);
            const total = parseInt(cents(data.total_contract_rent), 10);
            if ([tenant, ha, total].some(Number.isNaN)) return null;
            return { matches: tenant + ha === total, shortBy: total - tenant - ha };
        } catch {
            return null;
        }
    })();

    const field = (name, label, extra = {}) => (
        <FormField
            label={label}
            value={data[name]}
            onChange={(e) => setData(name, e.target.value)}
            error={errors[name]}
            {...extra}
        />
    );

    return (
        <AdminLayout header={editing ? 'Edit lease' : 'New lease'}>
            <Head title={editing ? 'Edit lease' : 'New lease'} />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {errors.lock_version && (
                <Alert tone="error" className="mb-4" title="This lease changed while you were editing">
                    {errors.lock_version}
                </Alert>
            )}

            <form onSubmit={submit} noValidate className="max-w-3xl">
                <fieldset className="mb-6">
                    <legend className="mb-2 text-lg font-semibold text-gray-900">Who and where</legend>

                    <FormField label="Tenant" error={errors.tenant_id} required>
                        <select
                            value={data.tenant_id}
                            onChange={(e) => setData('tenant_id', e.target.value)}
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        >
                            <option value="">Choose a tenant…</option>
                            {tenants.map((t) => (
                                <option key={t.id} value={t.id}>{t.name}</option>
                            ))}
                        </select>
                    </FormField>

                    <FormField label="Unit" error={errors.unit_id} required>
                        <select
                            value={data.unit_id}
                            onChange={(e) => setData('unit_id', e.target.value)}
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        >
                            <option value="">Choose a unit…</option>
                            {/* Grouped by property, and a property with no units
                                still appears — carrying a disabled row that says
                                what to do. Omitting it made a newly added
                                property look as though it had not saved. */}
                            {properties.map((p) => (
                                <optgroup key={p.id} label={p.name}>
                                    {p.units.length === 0 ? (
                                        <option value="" disabled>
                                            no units yet — add one first
                                        </option>
                                    ) : (
                                        p.units.map((u) => (
                                            <option key={u.id} value={u.id}>
                                                unit {u.unit_number}
                                                {u.status === 'occupied' ? ' (occupied)' : ''}
                                            </option>
                                        ))
                                    )}
                                </optgroup>
                            ))}
                        </select>
                    </FormField>

                    <div className="grid gap-x-4 sm:grid-cols-2">
                        {field('start_date', 'Lease start', { type: 'date', required: true })}
                        {field('end_date', 'Lease expires', { type: 'date', required: true })}
                    </div>
                </fieldset>

                <fieldset className="mb-6">
                    <legend className="mb-2 text-lg font-semibold text-gray-900">Rent</legend>

                    <div className="grid gap-x-4 sm:grid-cols-3">
                        {field('total_contract_rent', 'Total contract rent', { inputMode: 'decimal', required: true })}
                        {field('tenant_portion', 'Tenant portion', { inputMode: 'decimal', required: true })}
                        {field('ha_portion', 'Housing authority portion', { inputMode: 'decimal', required: true })}
                    </div>

                    {portionsSum && !portionsSum.matches && (
                        <Alert tone="warning" className="mb-4" title="Portions do not add up yet">
                            Tenant portion plus housing authority portion must equal the total contract
                            rent. Currently out by <Money value={String(portionsSum.shortBy / 100)} />.
                        </Alert>
                    )}

                    <div className="grid gap-x-4 sm:grid-cols-2">
                        {field('rent_due_day', 'Rent due day', {
                            type: 'number', min: 1, max: 28, required: true,
                            hint: 'Between 1 and 28. Later days do not exist in every month.',
                        })}
                        {field('grace_period_days', 'Grace period (days)', { type: 'number', min: 0, max: 60, required: true })}
                    </div>

                    {field('utility_responsibility', 'Utility responsibility', { maxLength: 255 })}
                    {field('security_deposit', 'Security deposit', { inputMode: 'decimal', required: true })}
                </fieldset>

                <fieldset className="mb-6">
                    <legend className="mb-2 text-lg font-semibold text-gray-900">Section 8</legend>

                    <label className="mb-3 flex min-h-touch items-center gap-3">
                        <input
                            type="checkbox"
                            checked={data.is_subsidised}
                            onChange={(e) => setData('is_subsidised', e.target.checked)}
                            className="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                        />
                        <span className="text-base">This is a subsidised tenancy</span>
                    </label>

                    {data.is_subsidised && (
                        <>
                            <FormField label="Section 8 agency" error={errors.housing_authority_id} required>
                                <select
                                    value={data.housing_authority_id}
                                    onChange={(e) => setData('housing_authority_id', e.target.value)}
                                    className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                                >
                                    <option value="">Choose an agency…</option>
                                    {authorities.map((a) => (
                                        <option key={a.id} value={a.id}>{a.name}</option>
                                    ))}
                                </select>
                            </FormField>

                            {field('hap_contract_number', 'HAP contract number', { maxLength: 60 })}
                        </>
                    )}
                </fieldset>

                <fieldset className="mb-6">
                    <legend className="mb-2 text-lg font-semibold text-gray-900">Fees</legend>
                    <p className="mb-3 max-w-prose text-sm text-gray-600">
                        Late fees apply to the tenant portion only, never to the housing authority
                        portion.
                    </p>

                    <div className="grid gap-x-4 sm:grid-cols-2">
                        {field('late_fee_flat', 'Flat late fee', { inputMode: 'decimal', required: true })}
                        {field('late_fee_daily', 'Daily late fee', { inputMode: 'decimal', required: true })}
                        {field('late_fee_max', 'Maximum late fee', {
                            inputMode: 'decimal', hint: 'Leave blank for no cap.',
                        })}
                        {field('returned_payment_fee', 'Returned payment fee', { inputMode: 'decimal', required: true })}
                    </div>

                    {/* A daily fee with no ceiling accrues every night for as
                        long as the rent is unpaid. Six months of $5 a day is
                        $900 on top of the rent, which a Georgia magistrate is
                        unlikely to think reasonable — and it is the kind of
                        figure nobody notices until it is in a filing. */}
                    {(() => {
                        const daily = parseInt(
                            (formatMoney(data.late_fee_daily || '0').text.replace(/[$,]/g, '').replace('.', '')),
                            10,
                        );
                        const uncapped = String(data.late_fee_max ?? '').trim() === '';

                        return daily > 0 && uncapped ? (
                            <Alert tone="warning" className="mb-4" title="This daily fee has no ceiling">
                                A daily fee with no maximum keeps accruing for as long as the rent is
                                unpaid. Set a maximum unless you have been advised otherwise.
                            </Alert>
                        ) : null;
                    })()}
                </fieldset>

                <fieldset className="mb-6">
                    <legend className="mb-2 text-lg font-semibold text-gray-900">Partial payments</legend>

                    <FormField label="Policy" error={errors.partial_payment_policy} required>
                        <select
                            value={data.partial_payment_policy}
                            onChange={(e) => setData('partial_payment_policy', e.target.value)}
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        >
                            <option value="full_only">Full payment only</option>
                            <option value="partial_allowed">Partial payments allowed</option>
                            <option value="before_due_only">Partial allowed before the due date only</option>
                            <option value="under_arrangement_only">Partial allowed under an arrangement only</option>
                        </select>
                    </FormField>

                    {data.partial_payment_policy !== 'full_only' && (
                        <div className="grid gap-x-4 sm:grid-cols-2">
                            {field('partial_minimum_amount', 'Minimum partial payment', { inputMode: 'decimal' })}
                            {field('partial_policy_expires_on', 'Policy expires on', { type: 'date' })}
                        </div>
                    )}

                    <label className="mb-3 flex min-h-touch items-center gap-3">
                        <input
                            type="checkbox"
                            checked={data.partial_requires_approval}
                            onChange={(e) => setData('partial_requires_approval', e.target.checked)}
                            className="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                        />
                        <span className="text-base">Partial payments need admin approval</span>
                    </label>

                    <label className="mb-3 flex min-h-touch items-center gap-3">
                        <input
                            type="checkbox"
                            checked={data.ledger_review_required}
                            onChange={(e) => setData('ledger_review_required', e.target.checked)}
                            className="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                        />
                        <span className="text-base">Require a reviewed ledger before accepting partial payment</span>
                    </label>
                </fieldset>

                <FormField label="Lease status" error={errors.status} required>
                    <select
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        // Ending a lease needs an effective date and a reason,
                        // so it is a deliberate action rather than a dropdown
                        // value someone changes in passing (FR-REG-03).
                        disabled={data.status === 'ended'}
                    >
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        {data.status === 'ended' && <option value="ended">Ended</option>}
                    </select>
                </FormField>

                <div className="mt-2 flex flex-col-reverse gap-3 sm:flex-row">
                    <Link
                        href="/admin/leases"
                        className="inline-flex min-h-touch items-center justify-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="min-h-touch rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : editing ? 'Save changes' : 'Create lease'}
                    </button>

                    {editing && data.status !== 'ended' && (
                        <button
                            type="button"
                            onClick={() => setTerminating(true)}
                            className="inline-flex min-h-touch items-center justify-center rounded-md border border-overdue-border px-4 text-base font-medium text-overdue-fg hover:bg-overdue-bg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg sm:ml-auto"
                        >
                            End lease
                        </button>
                    )}
                </div>
            </form>

            <ConfirmDialog
                open={terminating}
                title="End this lease?"
                confirmLabel="End lease"
                tone="danger"
                processing={terminate.processing}
                onCancel={() => setTerminating(false)}
                onConfirm={() =>
                    terminate.post(`/admin/leases/${lease?.id}/terminate`, {
                        onSuccess: () => setTerminating(false),
                    })
                }
            >
                <p className="mb-3">
                    No further rent will be charged from the effective date. Existing entries and any
                    outstanding balance stay on the account.
                </p>
                <FormField
                    label="Effective date"
                    type="date"
                    value={terminate.data.effective_date}
                    onChange={(e) => terminate.setData('effective_date', e.target.value)}
                    error={terminate.errors.effective_date}
                    required
                />
                <FormField
                    label="Reason"
                    value={terminate.data.reason}
                    onChange={(e) => terminate.setData('reason', e.target.value)}
                    error={terminate.errors.reason}
                    maxLength={500}
                />
            </ConfirmDialog>
        </AdminLayout>
    );
}
