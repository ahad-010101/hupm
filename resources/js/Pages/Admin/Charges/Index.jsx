import { useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';
import ConfirmDialog from '@/Components/ConfirmDialog';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import FormField from '@/Components/FormField';
import Money, { formatMoney } from '@/Components/Money';
import StatusBadge from '@/Components/StatusBadge';

/** Cents from a decimal string, without ever making a number of money (I-10). */
function toCents(value) {
    const trimmed = String(value ?? '').trim();

    if (trimmed === '') {
        return 0;
    }

    try {
        return parseInt(formatMoney(trimmed).text.replace(/[$,]/g, '').replace('.', ''), 10);
    } catch {
        return 0;
    }
}

function fromCents(cents) {
    const sign = cents < 0 ? '-' : '';
    const absolute = Math.abs(cents);

    return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
}

/**
 * Charging many residents at once.  [WP-41, FR-CHG-01]
 *
 * The confirmation step is the point of the screen, not decoration. A bulk
 * action on money that fires on one click is the wrong shape — so the count and
 * the total are read back before anything posts, and the server checks the
 * count it was shown still matches.
 */
export default function Index({
    types = [],
    properties = [],
    leases = [],
    batches = [],
    schedules = [],
    flash = {},
    errors = {},
}) {
    const [confirming, setConfirming] = useState(false);
    const [reversing, setReversing] = useState(null);

    const form = useForm({
        charge_type_id: '',
        description: '',
        amount: '',
        scope: 'all',
        property_id: '',
        lease_ids: [],
        timing: 'monthly',
        confirmed_lease_count: 0,
    });

    const reversal = useForm({ reason: '' });

    // Who this will actually hit, worked out here so the figures the admin
    // reads are the figures they confirm.
    const targeted = useMemo(() => {
        if (form.data.scope === 'property') {
            return leases.filter((l) => String(l.property_id) === String(form.data.property_id));
        }

        if (form.data.scope === 'selected') {
            return leases.filter((l) => form.data.lease_ids.includes(l.id));
        }

        return leases;
    }, [form.data.scope, form.data.property_id, form.data.lease_ids, leases]);

    const totalCents = toCents(form.data.amount) * targeted.length;

    const pickType = (id) => {
        const type = types.find((t) => String(t.id) === String(id));

        form.setData((data) => ({
            ...data,
            charge_type_id: id,
            // Both stay editable — the type is a starting point, not a rule.
            description: type?.default_description ?? '',
            amount: type?.default_amount ?? '',
        }));
    };

    const toggleLease = (id) => {
        form.setData(
            'lease_ids',
            form.data.lease_ids.includes(id)
                ? form.data.lease_ids.filter((x) => x !== id)
                : [...form.data.lease_ids, id],
        );
    };

    const review = (e) => {
        e.preventDefault();
        form.setData('confirmed_lease_count', targeted.length);
        setConfirming(true);
    };

    const post = () => {
        form.post('/admin/charges', {
            preserveScroll: true,
            onSuccess: () => { setConfirming(false); form.reset(); },
            onError: () => setConfirming(false),
        });
    };

    const chosenType = types.find((t) => String(t.id) === String(form.data.charge_type_id));

    return (
        <AdminLayout header="Post a charge">
            <Head title="Post a charge" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {(errors.scope || errors.amount || errors.reason) && (
                <Alert tone="error" className="mb-4" title="That could not be posted">
                    {errors.scope || errors.amount || errors.reason}
                </Alert>
            )}

            {types.length === 0 ? (
                <EmptyState
                    title="No charge types yet."
                    description="Define what you charge for — garbage collection, pest control — before posting one."
                    action={
                        <Link
                            href="/admin/charges/types"
                            className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700"
                        >
                            Add a charge type
                        </Link>
                    }
                />
            ) : (
                <form onSubmit={review} className="mb-8 rounded-lg border border-gray-200 bg-white p-6">
                    <FormField label="Purpose" error={form.errors.charge_type_id} required>
                        <select
                            value={form.data.charge_type_id}
                            onChange={(e) => pickType(e.target.value)}
                            required
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        >
                            <option value="">Choose…</option>
                            {types.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name} — ${t.default_amount}
                                </option>
                            ))}
                        </select>
                    </FormField>

                    <FormField
                        label="Wording on the resident's ledger"
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        error={form.errors.description}
                        maxLength={150}
                        required
                    />

                    <FormField
                        label="Amount each"
                        value={form.data.amount}
                        onChange={(e) => form.setData('amount', e.target.value)}
                        error={form.errors.amount}
                        type="number"
                        inputMode="decimal"
                        min="0.01"
                        step="0.01"
                        required
                    />

                    <fieldset className="mt-4">
                        <legend className="text-base font-medium text-gray-900">Who pays it</legend>
                        <div className="mt-2 space-y-2">
                            {[
                                { value: 'all', label: `Everyone with an active lease (${leases.length})` },
                                { value: 'property', label: 'Everyone at one property' },
                                { value: 'selected', label: 'Choose residents individually' },
                            ].map((option) => (
                                <label
                                    key={option.value}
                                    className="flex min-h-touch items-center gap-3 rounded-md border border-gray-300 px-3 py-2 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"
                                >
                                    <input
                                        type="radio"
                                        name="scope"
                                        value={option.value}
                                        checked={form.data.scope === option.value}
                                        onChange={() => form.setData('scope', option.value)}
                                        className="text-brand-600 focus:ring-brand-600"
                                    />
                                    <span className="text-base text-gray-900">{option.label}</span>
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    {form.data.scope === 'property' && (
                        <FormField label="Property" error={form.errors.property_id} required>
                            <select
                                value={form.data.property_id}
                                onChange={(e) => form.setData('property_id', e.target.value)}
                                className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                            >
                                <option value="">Choose…</option>
                                {properties.map((p) => (
                                    <option key={p.id} value={p.id}>{p.name}</option>
                                ))}
                            </select>
                        </FormField>
                    )}

                    {form.data.scope === 'selected' && (
                        <fieldset className="mt-4">
                            <legend className="text-base font-medium text-gray-900">
                                Residents ({form.data.lease_ids.length} selected)
                            </legend>
                            <ul className="mt-2 max-h-72 space-y-1 overflow-y-auto rounded-md border border-gray-200 p-2">
                                {leases.map((lease) => (
                                    <li key={lease.id}>
                                        <label className="flex min-h-touch items-center gap-3 rounded px-2 text-base hover:bg-gray-50">
                                            <input
                                                type="checkbox"
                                                checked={form.data.lease_ids.includes(lease.id)}
                                                onChange={() => toggleLease(lease.id)}
                                                className="rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                                            />
                                            <span>
                                                {lease.tenant}
                                                <span className="text-gray-600">
                                                    {' '}— {lease.property}, unit {lease.unit}
                                                </span>
                                            </span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </fieldset>
                    )}

                    <fieldset className="mt-4">
                        <legend className="text-base font-medium text-gray-900">When</legend>
                        <div className="mt-2 space-y-2">
                            {[
                                {
                                    value: 'monthly',
                                    label: 'Every month, with the rent',
                                    note: 'Posts automatically from next month.',
                                },
                                {
                                    value: 'once',
                                    label: 'Once, now',
                                    note: 'Posts immediately, and can be reversed as one batch.',
                                },
                            ].map((option) => (
                                <label
                                    key={option.value}
                                    className="flex min-h-touch items-center gap-3 rounded-md border border-gray-300 px-3 py-2 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"
                                >
                                    <input
                                        type="radio"
                                        name="timing"
                                        value={option.value}
                                        checked={form.data.timing === option.value}
                                        onChange={() => form.setData('timing', option.value)}
                                        className="text-brand-600 focus:ring-brand-600"
                                    />
                                    <span className="text-base text-gray-900">{option.label}</span>
                                    <span className="ml-auto text-sm text-gray-600">{option.note}</span>
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <button
                        type="submit"
                        disabled={targeted.length === 0 || !form.data.charge_type_id}
                        className="mt-5 inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        Review {targeted.length} {targeted.length === 1 ? 'charge' : 'charges'}
                    </button>
                </form>
            )}

            {schedules.length > 0 && (
                <section className="mb-8">
                    <h2 className="mb-3 text-lg font-semibold text-gray-900">Charged every month</h2>
                    <DataTable
                        caption="Monthly charges"
                        rows={schedules}
                        columns={[
                            { key: 'name', header: 'Purpose' },
                            {
                                key: 'leases',
                                header: 'Residents',
                                align: 'right',
                                render: (s) => s.leases,
                            },
                            {
                                key: 'monthly_total',
                                header: 'Each month',
                                align: 'right',
                                render: (s) => <Money value={s.monthly_total} />,
                            },
                            {
                                key: 'actions',
                                header: 'Actions',
                                align: 'right',
                                render: (s) => (
                                    <Link
                                        as="button"
                                        method="post"
                                        href={`/admin/charges/types/${s.charge_type_id}/stop`}
                                        className="underline text-overdue-fg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg"
                                    >
                                        Stop
                                    </Link>
                                ),
                            },
                        ]}
                    />
                    <p className="mt-2 text-sm text-gray-600">
                        Stopping ends the arrangement from next month. Charges already posted stay on
                        each resident's ledger.
                    </p>
                </section>
            )}

            {batches.length > 0 && (
                <section>
                    <h2 className="mb-3 text-lg font-semibold text-gray-900">Recently posted</h2>
                    <DataTable
                        caption="One-off charges"
                        rows={batches}
                        columns={[
                            { key: 'posted_at', header: 'Date' },
                            { key: 'description', header: 'What' },
                            {
                                key: 'lease_count',
                                header: 'Residents',
                                align: 'right',
                                render: (b) => b.lease_count,
                            },
                            {
                                key: 'total_amount',
                                header: 'Total',
                                align: 'right',
                                render: (b) => <Money value={b.total_amount} />,
                            },
                            {
                                key: 'status',
                                header: 'Status',
                                render: (b) =>
                                    b.is_reversed ? (
                                        <StatusBadge status="reversed" label="Reversed" tone="neutral" />
                                    ) : (
                                        <StatusBadge status="posted" label="Posted" tone="settled" />
                                    ),
                            },
                            {
                                key: 'actions',
                                header: 'Actions',
                                align: 'right',
                                render: (b) =>
                                    b.is_reversed ? (
                                        <span className="text-sm text-gray-600">{b.reversed_reason}</span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => setReversing(b)}
                                            className="underline text-overdue-fg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg"
                                        >
                                            Reverse
                                        </button>
                                    ),
                            },
                        ]}
                    />
                </section>
            )}

            <ConfirmDialog
                open={confirming}
                title="Post this charge?"
                confirmLabel={form.data.timing === 'monthly' ? 'Set it up' : 'Post it'}
                processing={form.processing}
                onCancel={() => setConfirming(false)}
                onConfirm={post}
            >
                {form.data.timing === 'monthly' ? (
                    <>
                        This charges <strong>{targeted.length}</strong>{' '}
                        {targeted.length === 1 ? 'resident' : 'residents'}{' '}
                        <Money value={form.data.amount || '0.00'} /> each,{' '}
                        <strong>every month</strong> — <Money value={fromCents(totalCents)} /> a month
                        in total. It posts with the rent, starting next month. Residents already on{' '}
                        {chosenType?.name} will be updated to this amount.
                    </>
                ) : (
                    <>
                        This charges <strong>{targeted.length}</strong>{' '}
                        {targeted.length === 1 ? 'resident' : 'residents'}{' '}
                        <Money value={form.data.amount || '0.00'} /> each,{' '}
                        <strong><Money value={fromCents(totalCents)} /> in total</strong>, right now.
                        You can reverse the whole batch afterwards if it is wrong.
                    </>
                )}
            </ConfirmDialog>

            <ConfirmDialog
                open={reversing !== null}
                title="Reverse this batch?"
                confirmLabel="Reverse"
                tone="danger"
                processing={reversal.processing}
                onCancel={() => { setReversing(null); reversal.reset(); }}
                onConfirm={() =>
                    reversal.post(`/admin/charges/batches/${reversing.id}/reverse`, {
                        preserveScroll: true,
                        onSuccess: () => { setReversing(null); reversal.reset(); },
                    })
                }
            >
                <p className="mb-3">
                    This credits {reversing?.lease_count}{' '}
                    {reversing?.lease_count === 1 ? 'resident' : 'residents'} back. The original
                    charges stay on each ledger beside the reversal — nothing is deleted.
                </p>
                <FormField
                    label="Reason"
                    value={reversal.data.reason}
                    onChange={(e) => reversal.setData('reason', e.target.value)}
                    error={reversal.errors.reason}
                    maxLength={500}
                    hint="Why this is being reversed. Becomes part of the permanent record."
                    required
                />
            </ConfirmDialog>
        </AdminLayout>
    );
}
