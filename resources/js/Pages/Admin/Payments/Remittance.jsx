import { useMemo, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import FormField from '@/Components/FormField';
import Alert from '@/Components/Alert';
import EmptyState from '@/Components/EmptyState';
import Money, { formatMoney } from '@/Components/Money';

/**
 * Split one Housing Authority cheque across tenants.  [GATE Q-2, risk R-9]
 *
 * The authority sends one payment covering many residents. The ledger is per
 * tenant, so someone has to say which tenants it covers — and **the system
 * cannot guess**. What it can do is show what each tenant owes on the authority
 * portion, do the arithmetic live, and refuse a split that does not add up.
 *
 * The suggested figures are each tenant's outstanding authority balance. They
 * are a starting point for reading the remittance advice, not an answer.
 */

/**
 * Cents as an integer, via string manipulation only — never parseFloat (I-10).
 *
 * Blank returns null rather than zero. "Nothing entered yet" and "entered
 * zero" are different states, and collapsing them made an empty form read
 * "$0.00 assigned of $0.00 · Balanced".
 */
function toCents(value) {
    const trimmed = String(value ?? '').trim();

    if (trimmed === '') {
        return null;
    }

    try {
        return parseInt(formatMoney(trimmed).text.replace(/[$,]/g, '').replace('.', ''), 10);
    } catch {
        return null;
    }
}

/**
 * The rows that carry money, in the shape the endpoint expects.
 *
 * Exported so the payload the browser actually sends is testable. A feature
 * test posting straight to the route cannot see this step, which is exactly
 * where the split went missing once already.
 *
 * @param {Array<{lease_id: number}>} lines
 * @param {Record<number, string>} amounts
 */
export function assignedLines(lines, amounts) {
    return lines
        .filter((line) => (toCents(amounts[line.lease_id]) ?? 0) > 0)
        .map((line) => ({ lease_id: line.lease_id, amount: amounts[line.lease_id] }));
}

function fromCents(cents) {
    const sign = cents < 0 ? '-' : '';
    const magnitude = Math.abs(cents);

    return `${sign}${Math.floor(magnitude / 100)}.${String(magnitude % 100).padStart(2, '0')}`;
}

export default function Remittance({
    authorities,
    authority,
    lines,
    today,
    idempotencyKey,
    flash = {},
}) {
    const [amounts, setAmounts] = useState(() =>
        Object.fromEntries(lines.map((l) => [l.lease_id, ''])),
    );

    const { data, setData, post, transform, processing, errors } = useForm({
        housing_authority_id: authority?.id ?? '',
        total: '',
        received_on: today,
        reference: '',
        note: '',
        idempotency_key: idempotencyKey,
        lines: [],
    });

    const chooseAuthority = (id) => {
        // A different authority is a different set of tenants, so the page
        // reloads rather than filtering client-side — the outstanding balances
        // have to come from the ledger, not from memory.
        router.get('/admin/payments/remittance', id ? { housing_authority_id: id } : {}, {
            preserveScroll: true,
        });
    };

    const tally = useMemo(() => {
        const total = toCents(data.total);
        let assigned = 0;
        let invalid = false;

        for (const line of lines) {
            // A blank row is simply not assigned; only a malformed figure is a
            // problem worth telling the admin about.
            if (String(amounts[line.lease_id] ?? '').trim() === '') {
                continue;
            }

            const cents = toCents(amounts[line.lease_id]);

            if (cents === null) {
                invalid = true;
                continue;
            }

            assigned += cents;
        }

        return {
            invalid,
            assigned,
            total,
            remaining: total === null ? null : total - assigned,
            balanced: total !== null && !invalid && total === assigned && total > 0,
        };
    }, [amounts, data.total, lines]);

    const fillFromOutstanding = () => {
        setAmounts(
            Object.fromEntries(
                lines.map((l) => [l.lease_id, toCents(l.outstanding) > 0 ? l.outstanding : '']),
            ),
        );
    };

    const submit = (e) => {
        e.preventDefault();

        // `transform`, not a `data` option on post(): useForm sends its own
        // state and silently ignores a data override, so the lines never left
        // the browser and the server saw an empty split.
        transform((form) => ({ ...form, lines: assignedLines(lines, amounts) }));

        post('/admin/payments/remittance');
    };

    return (
        <AdminLayout header="Housing authority remittance">
            <Head title="Housing authority remittance" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            <p className="mb-4 max-w-2xl text-base text-gray-700">
                One payment from an authority covering several residents. Enter the amount on the
                remittance, then assign it to the tenants it covers. The suggested figures are what
                each tenant currently owes on the authority portion — check them against the
                authority’s remittance advice before saving.
            </p>

            <FormField label="Housing authority" error={errors.housing_authority_id} required className="max-w-md">
                <select
                    value={data.housing_authority_id}
                    onChange={(e) => {
                        setData('housing_authority_id', e.target.value);
                        chooseAuthority(e.target.value);
                    }}
                    className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                >
                    <option value="">Choose an authority…</option>
                    {authorities.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.name}
                            {a.is_lump_sum ? ' (remits lump sums)' : ''}
                        </option>
                    ))}
                </select>
            </FormField>

            {!authority && (
                <EmptyState
                    title="Choose an authority to begin."
                    description="The tenants it pays for, and what each is owed, load once you pick one."
                />
            )}

            {authority && lines.length === 0 && (
                <EmptyState
                    title={`${authority.name} has no active leases.`}
                    description="Assign the authority to a lease before recording a remittance from it."
                />
            )}

            {authority && lines.length > 0 && (
                <form onSubmit={submit} noValidate>
                    <div className="mb-4 grid max-w-3xl gap-x-4 sm:grid-cols-3">
                        <FormField
                            label="Remittance amount"
                            value={data.total}
                            onChange={(e) => setData('total', e.target.value)}
                            error={errors.total}
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
                            required
                        />
                        <FormField
                            label="Reference"
                            value={data.reference}
                            onChange={(e) => setData('reference', e.target.value)}
                            error={errors.reference}
                            hint="Remittance advice number."
                            maxLength={100}
                        />
                    </div>

                    {errors.lines && (
                        <Alert tone="error" className="mb-4" title="This split does not add up">
                            {errors.lines}
                        </Alert>
                    )}

                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold text-gray-900">
                            Assign to tenants
                        </h2>
                        <button
                            type="button"
                            onClick={fillFromOutstanding}
                            className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            Suggest from outstanding
                        </button>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200">
                            <caption className="sr-only">
                                Tenants under {authority.name}, with the authority portion each owes
                            </caption>
                            <thead className="bg-gray-50">
                                <tr>
                                    <th scope="col" className="px-3 py-2 text-left text-sm font-semibold text-gray-900">
                                        Tenant
                                    </th>
                                    <th scope="col" className="hidden px-3 py-2 text-left text-sm font-semibold text-gray-900 sm:table-cell">
                                        Unit
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right text-sm font-semibold text-gray-900">
                                        Monthly
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right text-sm font-semibold text-gray-900">
                                        Outstanding
                                    </th>
                                    <th scope="col" className="px-3 py-2 text-right text-sm font-semibold text-gray-900">
                                        Apply
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {lines.map((line) => (
                                    <tr key={line.lease_id}>
                                        <td className="px-3 py-2 text-base">{line.tenant}</td>
                                        <td className="hidden px-3 py-2 text-base text-gray-600 sm:table-cell">
                                            {line.unit}
                                        </td>
                                        <td className="px-3 py-2 text-right text-base">
                                            <Money value={line.monthly} />
                                        </td>
                                        <td className="px-3 py-2 text-right text-base">
                                            <Money value={line.outstanding} />
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <label htmlFor={`apply-${line.lease_id}`} className="sr-only">
                                                Amount to apply to {line.tenant}
                                            </label>
                                            <input
                                                id={`apply-${line.lease_id}`}
                                                inputMode="decimal"
                                                placeholder="0.00"
                                                value={amounts[line.lease_id] ?? ''}
                                                onChange={(e) =>
                                                    setAmounts((prev) => ({
                                                        ...prev,
                                                        [line.lease_id]: e.target.value,
                                                    }))
                                                }
                                                className="min-h-touch w-32 rounded-md border-gray-300 text-right text-base focus:border-brand-600 focus:ring-brand-600"
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* The running difference, said as a difference. The admin is
                        reconciling a cheque, not doing subtraction. */}
                    <div
                        aria-live="polite"
                        className="mt-4 rounded-lg border border-gray-200 bg-white p-4 text-base"
                    >
                        {tally.invalid ? (
                            <span className="font-medium text-overdue-fg">
                                One of the amounts is not a valid figure.
                            </span>
                        ) : tally.total === null ? (
                            <span className="text-gray-600">
                                Enter the remittance amount to check the split.
                            </span>
                        ) : (
                            <>
                                <Money value={fromCents(tally.assigned)} /> assigned of{' '}
                                <Money value={fromCents(tally.total)} />
                                {/* The dot is a visual separator only. Left in the
                                    text stream a screen reader reads it straight
                                    into the figure before it. */}
                                <span aria-hidden="true" className="mx-2">
                                    ·
                                </span>
                                {tally.remaining === 0 ? (
                                    <span className="font-semibold text-settled-fg">Balanced</span>
                                ) : tally.remaining > 0 ? (
                                    <span className="font-semibold text-overdue-fg">
                                        <Money value={fromCents(tally.remaining)} /> still to assign
                                    </span>
                                ) : (
                                    <span className="font-semibold text-overdue-fg">
                                        <Money value={fromCents(-tally.remaining)} /> over the
                                        remittance
                                    </span>
                                )}
                            </>
                        )}
                    </div>

                    <div className="mt-4 flex gap-3">
                        <button
                            type="submit"
                            disabled={processing || !tally.balanced}
                            className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                        >
                            {processing ? 'Recording…' : 'Record remittance'}
                        </button>

                        <Link
                            href="/admin/payments"
                            className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            Cancel
                        </Link>
                    </div>

                    <p className="mt-3 text-sm text-gray-600">
                        Each tenant gets their own payment record, all sharing one batch reference,
                        so the cheque can be reassembled later. None of it touches a tenant balance.
                    </p>
                </form>
            )}
        </AdminLayout>
    );
}
