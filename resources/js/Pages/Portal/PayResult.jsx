import { Head, Link } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import Alert from '@/Components/Alert';
import Money from '@/Components/Money';

/**
 * Payment result.  [UI §3.3, FR-PAY-01 step 7]
 *
 * Three outcomes, and the wording matters more here than anywhere else in the
 * portal.
 *
 * **Never the word "failed"** about a payment in flight (UI §8). An ACH debit
 * takes 2–5 business days and can still be returned afterwards; a tenant told
 * their payment failed pays a second time, and then has two debits and a
 * complaint. "Processing" is both truthful and safe.
 *
 * The balance shown is deliberately the *unchanged* one — the payment has not
 * cleared, so it has not moved (I-6, BR-05). Saying so plainly here is what
 * stops the next question.
 */
export default function PayResult({ state, amount, reference, balance }) {
    return (
        <PortalLayout header="Payment" balance={balance}>
            <Head title="Payment" />

            {state === 'submitted' && (
                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    <Alert tone="success" title="Payment submitted">
                        Thank you. Your payment of <Money value={amount} /> is being processed.
                    </Alert>

                    <dl className="mt-4 text-base">
                        <div className="flex gap-2">
                            <dt className="text-gray-600">Reference</dt>
                            <dd className="font-medium text-gray-900">{reference}</dd>
                        </div>
                    </dl>

                    <p className="mt-4 text-base text-gray-700">
                        Bank payments take <strong>2–5 business days</strong> to clear. Until then
                        your balance still shows the full amount, with your payment listed as
                        processing beneath it. There is nothing further for you to do.
                    </p>

                    <p className="mt-2 text-base text-gray-700">
                        Your balance today is <Money value={balance} balance />. We will email you
                        a receipt once the payment clears.
                    </p>

                    <Link
                        href="/portal"
                        className="mt-4 inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Back to your account
                    </Link>
                </section>
            )}

            {state === 'unconfirmed' && (
                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    {/* Neither "submitted" nor "failed" — we genuinely do not
                        know, and the honest version is the safe one. A tenant
                        told this went through when their bank refused it will
                        not pay; one told it failed when it did will pay twice
                        (UI §8). */}
                    <Alert tone="warning" title="We have not had confirmation">
                        Your payment of <Money value={amount} /> has not been confirmed by your bank.
                        If the payment page showed you an error, nothing has been taken.
                    </Alert>

                    <p className="mt-4 text-base text-gray-700">
                        Your balance is unchanged at <Money value={balance} balance />. Please check
                        your bank before trying again, in case the payment did go through — or call
                        the office and we will look at it with you.
                    </p>

                    <div className="mt-4 flex flex-wrap gap-3">
                        <Link
                            href="/portal/pay"
                            className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            Try again
                        </Link>
                        <Link
                            href="/portal"
                            className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            Back to your account
                        </Link>
                    </div>
                </section>
            )}

            {state === 'cancelled' && (
                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    <Alert tone="info" title="Payment cancelled">
                        You came back without paying. <strong>Nothing has been charged.</strong>
                    </Alert>

                    <p className="mt-4 text-base text-gray-700">
                        Your balance is unchanged at <Money value={balance} balance />.
                    </p>

                    <div className="mt-4 flex flex-wrap gap-3">
                        <Link
                            href="/portal/pay"
                            className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            Try again
                        </Link>
                        <Link
                            href="/portal"
                            className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            Back to your account
                        </Link>
                    </div>
                </section>
            )}

            {state === 'unknown' && (
                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    {/* Reached by a refresh, a bookmark, or a browser back long
                        after the fact. Saying "we have no record" would alarm
                        someone whose payment is perfectly fine. */}
                    <Alert tone="info" title="Nothing in progress">
                        There is no payment waiting on your account right now.
                    </Alert>

                    <p className="mt-4 text-base text-gray-700">
                        Your balance is <Money value={balance} balance />. Any payment you have
                        already made appears on your account as processing until it clears.
                    </p>

                    <Link
                        href="/portal"
                        className="mt-4 inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Back to your account
                    </Link>
                </section>
            )}
        </PortalLayout>
    );
}
