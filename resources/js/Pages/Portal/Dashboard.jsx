import { Head, Link } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import Alert from '@/Components/Alert';
import EmptyState from '@/Components/EmptyState';
import Money from '@/Components/Money';

/**
 * Tenant dashboard.  [UI §3.1, FR-POR-01, AC-POR-01/02]
 *
 * One question, answered above the fold on a phone: what do I owe, and does
 * anything need me?
 *
 * INVARIANT I-4: the Housing Authority portion is not hidden here — it never
 * arrives. Everything on this screen comes from TenantAccountSummary, which
 * does not read it.
 *
 * The balance card shows balance and pending **separately** (UI §3.1). One
 * combined figure would be arithmetically defensible and practically
 * expensive: ACH takes 2–5 business days, and a tenant who cannot see their
 * payment in flight pays a second time.
 */

const DUE_COPY = {
    paid_up: (due) => `You are all paid up. Next rent is due ${due.next_due_on}.`,
    in_credit: (due) => `You are ahead. Next rent is due ${due.next_due_on}.`,
    due: (due) => `Rent is due ${due.next_due_on}.`,
    within_grace: (due) =>
        due.grace_ends_on
            ? `Rent was due and can still be paid without a late fee until ${due.grace_ends_on}.`
            : 'Rent is due now.',
    // Neutral and actionable, never accusatory (UI §8).
    past_due: (due) =>
        `This account is past due by ${due.days_past_due} ${due.days_past_due === 1 ? 'day' : 'days'}. ` +
        'Please contact management to arrange payment.',
};

function Card({ title, children, className = '' }) {
    return (
        <section className={`rounded-lg border border-gray-200 bg-white p-5 ${className}`}>
            {title && <h2 className="text-sm text-gray-600">{title}</h2>}
            {children}
        </section>
    );
}

export default function Dashboard({
    tenant = null,
    lease = null,
    address = null,
    balances = {},
    due = null,
    canPay = false,
    recentActivity = [],
    documents = [],
    notices = [],
    weatherAlerts = [],
    manager = {},
    orphaned = false,
}) {
    const { balance, pending, unconfirmed, error } = balances;
    const hasPending = pending && pending !== '0.00';
    // An attempt the gateway never heard of. Same money, different sentence —
    // "nothing more to do" is exactly the wrong thing to tell someone who
    // opened the payment form and closed it again.
    const isUnconfirmed = hasPending && unconfirmed && unconfirmed === pending;

    if (orphaned) {
        return (
            <PortalLayout header="Your account">
                <Head title="Your account" />
                <Alert tone="warning" title="We could not find your tenancy">
                    Your login is not linked to a tenancy yet. Please contact the office and we
                    will put that right.
                </Alert>
            </PortalLayout>
        );
    }

    return (
        <PortalLayout
            header={tenant ? `Hello, ${tenant.first_name}` : 'Your account'}
            balance={balance}
            pendingAmount={hasPending ? pending : null}
        >
            <Head title="Your account" />

            {/* Safety first, literally: an active weather alert outranks money. */}
            {weatherAlerts.map((alert) => (
                <Alert key={alert.id} tone="warning" className="mb-4" title={alert.event}>
                    {alert.headline}{' '}
                    {/* A plain <a>, not Inertia's Link. The public site is Blade
                        and its route group carries no Inertia middleware, so an
                        Inertia visit gets plain HTML back and renders it in an
                        error overlay instead of navigating — on the one link a
                        resident may be following in an emergency. */}
                    <a href="/emergency-maintenance" className="underline">
                        Emergency maintenance instructions
                    </a>
                </Alert>
            ))}

            {lease?.in_management_review && (
                <Alert tone="warning" className="mb-4" title="Please contact management">
                    Online payments are paused on this account. Your balance, history and documents
                    stay available.
                    {manager.phone && (
                        <>
                            {' '}Call{' '}
                            <a href={`tel:${manager.phone.replace(/[^\d+]/g, '')}`} className="font-semibold underline">
                                {manager.phone}
                            </a>
                            .
                        </>
                    )}
                </Alert>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <Card title="Current balance">
                    {error ? (
                        // UI §3.1: never render a possibly-wrong balance, and take
                        // the pay button with it.
                        <Alert tone="error" className="mt-2" title="Balance unavailable">
                            {error}
                        </Alert>
                    ) : (
                        <>
                            <p className="mt-1 text-3xl">
                                <Money value={balance} balance />
                            </p>

                            {hasPending && (
                                <p className="mt-2 text-base text-gray-700">
                                    {isUnconfirmed ? (
                                        <>
                                            You started a payment of <Money value={pending} /> but we
                                            have not had confirmation from your bank. If you did not
                                            finish it, nothing has been taken — you can start again
                                            below.
                                        </>
                                    ) : (
                                        <>
                                            <Money value={pending} /> is processing and will come off
                                            this balance once it clears with your bank. Nothing more
                                            to do.
                                        </>
                                    )}
                                </p>
                            )}

                            {due && (
                                <p className="mt-2 text-base text-gray-700">
                                    {DUE_COPY[due.state]?.(due)}
                                </p>
                            )}

                            {canPay && balance !== '0.00' && !balance?.startsWith('-') && (
                                <Link
                                    href="/portal/pay"
                                    className="mt-4 inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:w-auto"
                                >
                                    Pay now
                                </Link>
                            )}
                        </>
                    )}
                </Card>

                <Card title="Your tenancy">
                    {lease ? (
                        <dl className="mt-1 space-y-1 text-base">
                            {address && (
                                <div>
                                    <dt className="sr-only">Address</dt>
                                    <dd className="font-medium text-gray-900">{address}</dd>
                                </div>
                            )}
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-600">Monthly rent</dt>
                                <dd><Money value={lease.monthly_rent} /></dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-600">Lease started</dt>
                                <dd>{lease.starts_on}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-600">Lease expires</dt>
                                <dd>{lease.expires_on}</dd>
                            </div>
                        </dl>
                    ) : (
                        <p className="mt-1 text-base text-gray-700">
                            No active tenancy on file. Please contact the office.
                        </p>
                    )}
                </Card>
            </div>

            {notices.length > 0 && (
                <Card title="Notices" className="mt-4">
                    <ul className="mt-1 space-y-1 text-base">
                        {notices.map((notice) => (
                            <li key={notice.id}>
                                {notice.subject}{' '}
                                <span className="text-sm text-gray-600">· {notice.sent_on}</span>
                            </li>
                        ))}
                    </ul>

                    {/* Reached from here rather than from a sixth bottom tab.
                        Five is already the ceiling for a thumb-reachable bar at
                        375px, and notices are read rarely — one extra tap beats
                        shrinking every other target. */}
                    <Link
                        href="/portal/notices"
                        className="mt-3 inline-block text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Read your notices
                    </Link>
                </Card>
            )}

            <Card title="Recent activity" className="mt-4">
                {recentActivity.length === 0 ? (
                    <EmptyState
                        title="Nothing yet."
                        description="Charges and payments appear here as they happen."
                    />
                ) : (
                    <>
                        <ul className="mt-2 divide-y divide-gray-100">
                            {recentActivity.map((entry) => (
                                <li key={entry.id} className="flex items-baseline justify-between gap-4 py-2">
                                    <span>
                                        <span className="block text-base">{entry.description}</span>
                                        <span className="block text-sm text-gray-600">
                                            {entry.date}
                                            {/* From the server, not inferred from
                                                "did this move the balance" — a void
                                                attempt does not move it either, and
                                                calling that "processing" told a
                                                tenant money was on its way when
                                                none had been taken. Never the word
                                                "failed" (UI §8). */}
                                            {entry.state && ` · ${entry.state}`}
                                        </span>
                                    </span>
                                    <Money value={entry.amount} className="shrink-0" />
                                </li>
                            ))}
                        </ul>

                        <Link
                            href="/portal/ledger"
                            className="mt-3 inline-block text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            See your full history
                        </Link>
                    </>
                )}
            </Card>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <Card title="Documents">
                    {documents.length === 0 ? (
                        <p className="mt-1 text-base text-gray-700">Nothing shared with you yet.</p>
                    ) : (
                        <ul className="mt-1 space-y-1 text-base">
                            {documents.map((document) => (
                                <li key={document.id}>
                                    <Link
                                        href={`/portal/documents/${document.id}`}
                                        className="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                    >
                                        {document.title}
                                    </Link>
                                    <span className="block text-sm text-gray-600">{document.date}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card title="Your property manager">
                    <p className="mt-1 text-base font-medium text-gray-900">{manager.name}</p>
                    {manager.phone && (
                        <p className="mt-1 text-base">
                            <a href={`tel:${manager.phone.replace(/[^\d+]/g, '')}`} className="underline">
                                {manager.phone}
                            </a>
                        </p>
                    )}
                    {manager.emergency_phone && (
                        <p className="mt-1 text-base">
                            Emergencies:{' '}
                            <a href={`tel:${manager.emergency_phone.replace(/[^\d+]/g, '')}`} className="underline">
                                {manager.emergency_phone}
                            </a>
                        </p>
                    )}
                    {manager.address && (
                        <p className="mt-1 text-sm text-gray-600">{manager.address}</p>
                    )}
                </Card>
            </div>
        </PortalLayout>
    );
}
