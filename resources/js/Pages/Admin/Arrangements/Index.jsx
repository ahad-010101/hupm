import { useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';
import EmptyState from '@/Components/EmptyState';
import FormField from '@/Components/FormField';
import StatusBadge from '@/Components/StatusBadge';
import Money, { formatMoney } from '@/Components/Money';

/**
 * Payment arrangements.  [UI §3.x, API-ADM-19/20, BR-19]
 *
 * Drafting and approving are two acts, not one button. Approval generates a
 * written agreement, files it in the resident's vault and sends it for
 * signature — none of which should happen because somebody clicked the wrong
 * row.
 *
 * The instalment total is checked here as well as on the server, because an
 * agreement whose instalments do not add up to the balance is one both parties
 * will read differently later.
 */

/** Cents from a decimal string, without ever making a number of money (I-10). */
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

function fromCents(cents) {
    const sign = cents < 0 ? '-' : '';
    const magnitude = Math.abs(cents);

    return `${sign}${Math.floor(magnitude / 100)}.${String(magnitude % 100).padStart(2, '0')}`;
}

const DEFAULT_TERMS =
    'If any payment above is missed or short, this arrangement is in default and the whole '
    + 'remaining balance becomes immediately due. The landlord may then pursue any remedy '
    + 'available under the lease and under Georgia law, including a dispossessory proceeding.';

export default function Index({
    arrangements = [],
    leases = [],
    currentPeriod,
    flash = {},
    errors = {},
}) {
    const [instalments, setInstalments] = useState([{ due_on: '', amount: '' }]);
    const [defaulting, setDefaulting] = useState(null);

    const draft = useForm({
        lease_id: '',
        amount_accepted: '',
        late_fees_continue: true,
        default_terms: DEFAULT_TERMS,
        schedule: [],
    });

    const approve = useForm({});
    const markDefault = useForm({ reason: '' });
    const reviewLedger = useForm({});

    const lease = useMemo(
        () => leases.find((l) => String(l.id) === String(draft.data.lease_id)),
        [leases, draft.data.lease_id],
    );

    const tally = useMemo(() => {
        const owed = toCents(lease?.balance);
        const accepted = toCents(draft.data.amount_accepted) ?? 0;

        if (owed === null) {
            return null;
        }

        const remaining = owed - accepted;
        const scheduled = instalments.reduce((sum, i) => sum + (toCents(i.amount) ?? 0), 0);

        return {
            owed,
            remaining,
            scheduled,
            balances: scheduled === 0 || scheduled === remaining,
        };
    }, [lease, draft.data.amount_accepted, instalments]);

    const submitDraft = () => {
        draft.transform((form) => ({
            ...form,
            schedule: instalments.filter((i) => i.due_on && (toCents(i.amount) ?? 0) > 0),
        }));
        draft.post('/admin/arrangements', {
            preserveScroll: true,
            onSuccess: () => {
                draft.reset('amount_accepted');
                setInstalments([{ due_on: '', amount: '' }]);
            },
        });
    };

    const needsReview = leases.filter((l) => l.ledger_review_required && !l.ledger_reviewed);

    return (
        <AdminLayout header="Payment arrangements">
            <Head title="Payment arrangements" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {errors.arrangement && <Alert tone="error" className="mb-4">{errors.arrangement}</Alert>}

            {needsReview.length > 0 && (
                <Alert tone="info" className="mb-4" title="Ledgers waiting on a review">
                    {/* [GATE C1/Q-1] "Full ledger required" is undefined in the
                        source. This is the shipped reading of it, surfaced
                        where somebody can act on it. */}
                    <p className="mb-2">
                        These accounts are set to require a ledger review before a part payment is
                        accepted. Reviewing marks them cleared for {currentPeriod}.
                    </p>
                    <ul className="space-y-1">
                        {needsReview.map((l) => (
                            <li key={l.id} className="flex flex-wrap items-center gap-2">
                                <span>{l.tenant}</span>
                                <button
                                    type="button"
                                    disabled={reviewLedger.processing}
                                    onClick={() => reviewLedger.post(`/admin/arrangements/leases/${l.id}/review-ledger`, {
                                        preserveScroll: true,
                                    })}
                                    className="inline-flex min-h-touch items-center rounded-md border border-gray-300 bg-white px-3 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    Mark reviewed
                                </button>
                            </li>
                        ))}
                    </ul>
                </Alert>
            )}

            <section className="mb-6 rounded-lg border border-gray-200 bg-white p-5">
                <h2 className="mb-3 text-lg font-semibold text-gray-900">Draft an arrangement</h2>

                <FormField label="Resident" error={draft.errors.lease_id} required>
                    <select
                        value={draft.data.lease_id}
                        onChange={(e) => draft.setData('lease_id', e.target.value)}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        <option value="">Choose a resident…</option>
                        {leases.map((l) => (
                            <option key={l.id} value={l.id}>{l.label} — owes ${l.balance}</option>
                        ))}
                    </select>
                </FormField>

                {lease && (
                    <p className="-mt-2 mb-4 text-base text-gray-700">
                        Outstanding today: <Money value={lease.balance} />. This figure is written
                        into the agreement as the amount owed and does not change afterwards.
                    </p>
                )}

                <FormField
                    label="Amount accepted today"
                    value={draft.data.amount_accepted}
                    onChange={(e) => draft.setData('amount_accepted', e.target.value)}
                    error={draft.errors.amount_accepted}
                    inputMode="decimal"
                    placeholder="0.00"
                    required
                />

                <fieldset className="mb-4">
                    <legend className="mb-1 text-base font-medium text-gray-900">
                        When the rest is paid
                    </legend>
                    <p className="mb-2 text-sm text-gray-600">
                        Leave empty if the balance is payable in full immediately.
                    </p>

                    {instalments.map((instalment, index) => (
                        <div key={index} className="mb-2 flex flex-wrap gap-2">
                            <label className="sr-only" htmlFor={`due-${index}`}>Instalment date</label>
                            <input
                                id={`due-${index}`}
                                type="date"
                                value={instalment.due_on}
                                onChange={(e) => setInstalments(instalments.map((v, i) =>
                                    i === index ? { ...v, due_on: e.target.value } : v))}
                                className="min-h-touch rounded-md border-gray-300 text-base focus:border-brand-600 focus:ring-brand-600"
                            />
                            <label className="sr-only" htmlFor={`amt-${index}`}>Instalment amount</label>
                            <input
                                id={`amt-${index}`}
                                inputMode="decimal"
                                placeholder="0.00"
                                value={instalment.amount}
                                onChange={(e) => setInstalments(instalments.map((v, i) =>
                                    i === index ? { ...v, amount: e.target.value } : v))}
                                className="min-h-touch w-32 rounded-md border-gray-300 text-right text-base focus:border-brand-600 focus:ring-brand-600"
                            />
                            {instalments.length > 1 && (
                                <button
                                    type="button"
                                    onClick={() => setInstalments(instalments.filter((_, i) => i !== index))}
                                    className="min-h-touch px-2 text-base underline"
                                >
                                    Remove
                                </button>
                            )}
                        </div>
                    ))}

                    <button
                        type="button"
                        onClick={() => setInstalments([...instalments, { due_on: '', amount: '' }])}
                        className="min-h-touch text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Add an instalment
                    </button>
                </fieldset>

                {tally && (
                    <div aria-live="polite" className="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-base">
                        Remaining after today: <Money value={fromCents(tally.remaining)} />
                        {tally.scheduled > 0 && (
                            <>
                                <span aria-hidden="true" className="mx-2">·</span>
                                {tally.balances ? (
                                    <span className="font-semibold text-settled-fg">
                                        instalments add up
                                    </span>
                                ) : (
                                    <span className="font-semibold text-overdue-fg">
                                        instalments come to <Money value={fromCents(tally.scheduled)} />
                                    </span>
                                )}
                            </>
                        )}
                    </div>
                )}

                <label className="mb-4 flex items-start gap-3 text-base">
                    <input
                        type="checkbox"
                        checked={draft.data.late_fees_continue}
                        onChange={(e) => draft.setData('late_fees_continue', e.target.checked)}
                        className="mt-1 size-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                    />
                    <span>
                        Late fees keep applying to the remaining balance.
                        <span className="block text-sm text-gray-600">
                            Whichever you choose is stated on the agreement (BR-19).
                        </span>
                    </span>
                </label>

                <FormField label="What happens on a breach" error={draft.errors.default_terms} required>
                    <textarea
                        value={draft.data.default_terms}
                        onChange={(e) => draft.setData('default_terms', e.target.value)}
                        rows={4}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    />
                </FormField>

                <button
                    type="button"
                    disabled={draft.processing || !draft.data.lease_id || !draft.data.amount_accepted || !tally?.balances}
                    onClick={submitDraft}
                    className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
                >
                    {draft.processing ? 'Drafting…' : 'Draft arrangement'}
                </button>
            </section>

            {arrangements.length === 0 ? (
                <EmptyState
                    title="No arrangements yet."
                    description="Draft one when a resident cannot pay in full and you agree a plan."
                />
            ) : (
                <ul className="space-y-2">
                    {arrangements.map((a) => (
                        <li key={a.id} className="rounded-lg border border-gray-200 bg-white p-4">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <Link
                                    href={`/admin/ledger/${a.tenant_id}`}
                                    className="text-base font-semibold text-gray-900 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    {a.tenant}
                                </Link>
                                <StatusBadge status={a.status} />
                            </div>

                            <p className="mt-1 text-base text-gray-700">
                                Owed <Money value={a.total_owed} /> · accepted{' '}
                                <Money value={a.amount_accepted} /> · remaining{' '}
                                <Money value={a.remaining} />
                            </p>

                            <p className="mt-1 text-sm text-gray-600">
                                {a.created_on} ·{' '}
                                {a.instalments > 0
                                    ? `${a.instalments} instalment${a.instalments === 1 ? '' : 's'}`
                                    : 'payable in full'}
                                {' · '}
                                {a.late_fees_continue ? 'late fees continue' : 'late fees paused'}
                            </p>

                            <div className="mt-3 flex flex-wrap gap-3">
                                {a.status === 'draft' && (
                                    <button
                                        type="button"
                                        disabled={approve.processing}
                                        onClick={() => approve.post(`/admin/arrangements/${a.id}/approve`, {
                                            preserveScroll: true,
                                        })}
                                        className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
                                    >
                                        Approve and generate the agreement
                                    </button>
                                )}

                                {a.document_id && (
                                    <Link
                                        href={`/admin/documents?tenant_id=${a.tenant_id}`}
                                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                    >
                                        Open the agreement
                                    </Link>
                                )}

                                {['pending_signature', 'active'].includes(a.status) && (
                                    <button
                                        type="button"
                                        onClick={() => setDefaulting(defaulting === a.id ? null : a.id)}
                                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                    >
                                        Record a default
                                    </button>
                                )}
                            </div>

                            {defaulting === a.id && (
                                <div className="mt-3 rounded-md border border-gray-200 bg-gray-50 p-3">
                                    <FormField
                                        label="What happened?"
                                        value={markDefault.data.reason}
                                        onChange={(e) => markDefault.setData('reason', e.target.value)}
                                        error={markDefault.errors.reason}
                                        maxLength={500}
                                        required
                                    />
                                    <button
                                        type="button"
                                        disabled={markDefault.processing || markDefault.data.reason.trim().length < 3}
                                        onClick={() => markDefault.post(`/admin/arrangements/${a.id}/default`, {
                                            preserveScroll: true,
                                            onSuccess: () => { setDefaulting(null); markDefault.reset(); },
                                        })}
                                        className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-3 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
                                    >
                                        Record it
                                    </button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </AdminLayout>
    );
}
