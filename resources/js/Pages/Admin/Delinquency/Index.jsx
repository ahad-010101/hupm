import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';
import EmptyState from '@/Components/EmptyState';
import FormField from '@/Components/FormField';
import Money from '@/Components/Money';

/**
 * The Management Review queue.  [UI §4.2, API-ADM-17/18]
 *
 * This is a call list, not a report. Every account here is a household that
 * cannot pay online right now, so the phone number is on the card and the
 * balance is next to it.
 *
 * There is no bulk release, deliberately. Letting somebody out is a decision
 * about one household and it carries a reason that goes on the record.
 */
export default function Index({ accounts = [], triggerDay = 5, insideGrace = [], flash = {} }) {
    const [releasing, setReleasing] = useState(null);
    const release = useForm({ reason: '' });

    return (
        <AdminLayout header="Management Review">
            <Head title="Management Review" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            {insideGrace.length > 0 && (
                <Alert tone="warning" className="mb-4" title="Some leases enter review before their own grace ends">
                    {/* Two clocks that were never reconciled: BR-10 sets a
                        portfolio-wide day, BR-07 puts grace on the lease. A
                        resident inside their grace loses online payment while
                        not yet late under their own lease. */}
                    <p className="mb-2">
                        Review is triggered {triggerDay} days after the due date, portfolio-wide.
                        These leases grant more grace than that, so they lose online payment before
                        their lease says they are late — and before any late fee is due:
                    </p>
                    <ul className="ml-5 list-disc">
                        {insideGrace.map((l) => (
                            <li key={l.lease_id}>
                                {l.tenant} — {l.grace_days} days grace
                            </li>
                        ))}
                    </ul>
                </Alert>
            )}

            {accounts.length === 0 ? (
                <EmptyState
                    title="Nobody is under review."
                    description={`Accounts appear here ${triggerDay} days after the due date when a balance is outstanding.`}
                />
            ) : (
                <ul className="space-y-3">
                    {accounts.map((account) => (
                        <li key={account.lease_id} className="rounded-lg border border-gray-200 bg-white p-5">
                            <div className="flex flex-wrap items-baseline justify-between gap-3">
                                <Link
                                    href={`/admin/ledger/${account.tenant_id}`}
                                    className="text-base font-semibold text-gray-900 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    {account.tenant}
                                </Link>
                                <span className="text-lg">
                                    <Money value={account.balance} balance />
                                </span>
                            </div>

                            <p className="mt-1 text-base text-gray-700">
                                {account.property}
                                {account.unit && <> — unit {account.unit}</>}
                            </p>

                            {/* The point of the screen: reach the person. */}
                            {account.phone && (
                                <p className="mt-1 text-base">
                                    <a
                                        href={`tel:${account.phone.replace(/[^\d+]/g, '')}`}
                                        className="font-semibold underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                    >
                                        {account.phone}
                                    </a>
                                </p>
                            )}

                            <p className="mt-2 text-sm text-gray-600">
                                Under review since {account.since}
                                {account.days !== null && <> · {account.days} days</>}
                                {account.autopay_suspended && <> · autopay suspended</>}
                            </p>

                            {account.pending !== '0.00' && (
                                <p className="mt-1 text-sm text-gray-600">
                                    <Money value={account.pending} /> is already processing and will
                                    come off once it clears.
                                </p>
                            )}

                            <div className="mt-3 flex flex-wrap gap-3">
                                {/* I-12: admin-recorded payment works throughout,
                                    which is the whole of BR-12. */}
                                <Link
                                    href={`/admin/payments/record?lease_id=${account.lease_id}`}
                                    className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    Record a payment
                                </Link>
                                <button
                                    type="button"
                                    onClick={() => setReleasing(releasing === account.lease_id ? null : account.lease_id)}
                                    className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    Release from review
                                </button>
                            </div>

                            {releasing === account.lease_id && (
                                <div className="mt-3 rounded-md border border-gray-200 bg-gray-50 p-3">
                                    <p className="mb-2 text-sm text-gray-700">
                                        Autopay stays switched off after release. It has to be
                                        re-enabled explicitly.
                                    </p>
                                    <FormField
                                        label="Why is this account being released?"
                                        value={release.data.reason}
                                        onChange={(e) => release.setData('reason', e.target.value)}
                                        error={release.errors.reason}
                                        maxLength={500}
                                        required
                                    />
                                    <button
                                        type="button"
                                        disabled={release.processing || release.data.reason.trim().length < 3}
                                        onClick={() => release.post(`/admin/delinquency/${account.lease_id}/release`, {
                                            preserveScroll: true,
                                            onSuccess: () => { setReleasing(null); release.reset(); },
                                        })}
                                        className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
                                    >
                                        {release.processing ? 'Releasing…' : 'Release'}
                                    </button>
                                </div>
                            )}

                            {account.history.length > 0 && (
                                <details className="mt-3">
                                    <summary className="cursor-pointer text-base underline">
                                        Account history
                                    </summary>
                                    <ul className="mt-2 space-y-1 border-l-2 border-gray-200 pl-3 text-sm text-gray-600">
                                        {account.history.map((event, i) => (
                                            <li key={i}>
                                                {event.at} · {event.from} → {event.to} · by {event.by}
                                                <span className="block">{event.reason}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </AdminLayout>
    );
}
