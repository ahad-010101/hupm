import { useEffect, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import Alert from '@/Components/Alert';
import FormField from '@/Components/FormField';
import Money, { formatMoney } from '@/Components/Money';

/**
 * Make a payment.  [UI §3.2, FR-PAY-01]
 *
 * INVARIANT I-4: the Housing Authority portion is not hidden here — it never
 * arrives. Everything on this screen is the tenant's own portion.
 *
 * The submit goes to our own endpoint, which answers with a one-time Accept
 * Hosted token; the browser then posts that token to Authorize.Net. **Bank
 * details are typed on their page, never on ours** (AC-PAY-05) — which is why
 * there is no account-number field anywhere in this file, and why there could
 * not be one.
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

/** The inverse, and equally integer-only — no float ever touches money (I-10). */
function fromCents(cents) {
    const sign = cents < 0 ? '-' : '';
    const absolute = Math.abs(cents);

    return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
}

export default function Pay({
    balance,
    pending,
    unconfirmed,
    hasLease,
    inManagementReview,
    officePhone,
    policy,
    savedMethods = [],
    gatewayReady,
    idempotencyKey,
    leaseId,
    cardsEnabled = false,
    cardConvenienceFee = '0.00',
}) {
    const fullOnly = policy?.partial_payment_policy === 'full_only';
    const owed = toCents(balance) ?? 0;

    const [amount, setAmount] = useState(owed > 0 ? balance : '');
    const [method, setMethod] = useState('echeck');

    // The fee is only ever added to a card payment, and only when one is set.
    const feeOnCardCents = toCents(cardConvenienceFee) ?? 0;
    const feeCents = method === 'card' ? feeOnCardCents : 0;
    const totalCents = (toCents(amount) ?? 0) + feeCents;

    // A saved bank account cannot pay a card transaction and the reverse is
    // equally true, so the list follows the choice rather than offering
    // something that fails on the provider's page.
    const visibleSavedMethods = savedMethods.filter(
        (saved) => (saved.instrument_type ?? 'bank') === (method === 'card' ? 'card' : 'bank'),
    );
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const gatewayForm = useRef(null);

    // Once the token is in hand the browser posts it to Authorize.Net. Done as
    // a real form submission rather than a redirect because Accept Hosted
    // expects the token in a POST body.
    const [handoff, setHandoff] = useState(null);

    useEffect(() => {
        if (handoff) {
            gatewayForm.current?.submit();
        }
    }, [handoff]);

    const submit = async (e) => {
        e.preventDefault();

        if (submitting) {
            return;
        }

        setError(null);
        setSubmitting(true);

        try {
            const { data } = await window.axios.post('/portal/pay', {
                lease_id: leaseId,
                amount,
                idempotency_key: idempotencyKey,
                method,
            });

            setHandoff(data);
        } catch (failure) {
            const status = failure.response?.status;

            setError(
                failure.response?.data?.errors?.amount?.[0] ??
                    failure.response?.data?.errors?.method?.[0] ??
                    failure.response?.data?.message ??
                    'Something went wrong. Nothing has been charged. Please try again shortly.',
            );

            // A 429 or a 502 is not something a second click fixes, but a
            // validation error is — so the button comes back either way and the
            // message says which situation this is.
            setSubmitting(false);

            if (status === undefined) {
                setError('We could not reach the payment page. Nothing has been charged.');
            }
        }
    };

    /* AC-PAY-03: the form is replaced, not disabled. */
    if (inManagementReview) {
        return (
            <PortalLayout header="Make a payment" balance={balance}>
                <Head title="Make a payment" />

                <section className="rounded-lg border border-gray-200 bg-white p-6">
                    <h2 className="text-xl font-semibold text-gray-900">Contact Management</h2>
                    <p className="mt-2 text-base text-gray-700">
                        Online payments are paused on this account. Please contact management to
                        arrange payment — we can take a payment over the phone or in the office.
                    </p>
                    {officePhone && (
                        <p className="mt-3 text-lg">
                            <a
                                href={`tel:${officePhone.replace(/[^\d+]/g, '')}`}
                                className="font-semibold underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            >
                                {officePhone}
                            </a>
                        </p>
                    )}
                    <p className="mt-4 text-base text-gray-700">
                        Your balance and payment history stay available on your account.
                    </p>
                    <Link
                        href="/portal"
                        className="mt-4 inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Back to your account
                    </Link>
                </section>
            </PortalLayout>
        );
    }

    return (
        <PortalLayout header="Make a payment" balance={balance}>
            <Head title="Make a payment" />

            {!hasLease && (
                <Alert tone="warning" title="No active tenancy">
                    We could not find an active lease on your account. Please contact the office.
                </Alert>
            )}

            {hasLease && !gatewayReady && (
                <Alert tone="warning" title="Online payment is not available yet">
                    We are still setting up online payments. Please contact the office to pay by
                    cheque, money order or card in person.
                </Alert>
            )}

            {hasLease && gatewayReady && (
                <>
                    <section className="mb-4 rounded-lg border border-gray-200 bg-white p-6">
                        <h2 className="text-sm text-gray-600">Balance due</h2>
                        <p className="mt-1 text-3xl">
                            <Money value={balance} balance />
                        </p>

                        {pending && pending !== '0.00' && (
                            // The single most important line on the screen. A
                            // tenant who cannot see their payment in flight pays
                            // a second time (UI §3.1) — but one we cannot
                            // confirm must not be described as in flight, or
                            // they will not pay at all.
                            <p className="mt-2 text-base text-gray-700">
                                {unconfirmed === pending ? (
                                    <>
                                        You started a payment of <Money value={pending} /> that we
                                        have no confirmation for. If you did not finish it, nothing
                                        has been taken.
                                    </>
                                ) : (
                                    <>
                                        <Money value={pending} /> is already processing and will come
                                        off this balance once it clears with your bank.
                                    </>
                                )}
                            </p>
                        )}
                    </section>

                    {owed <= 0 ? (
                        <Alert tone="success" title="You are all paid up">
                            There is nothing to pay right now.
                        </Alert>
                    ) : (
                        <form onSubmit={submit} noValidate className="rounded-lg border border-gray-200 bg-white p-6">
                            {error && (
                                <Alert tone="error" className="mb-4" title="We could not start this payment">
                                    {error}
                                </Alert>
                            )}

                            {fullOnly ? (
                                <div className="mb-4">
                                    <p className="text-base font-medium text-gray-900">Amount</p>
                                    <p className="mt-1 text-2xl">
                                        <Money value={balance} />
                                    </p>
                                    <p className="mt-1 text-sm text-gray-600">
                                        This account pays the full balance. Contact the office if you
                                        need to arrange something different.
                                    </p>
                                </div>
                            ) : (
                                <FormField
                                    label="Amount to pay"
                                    value={amount}
                                    onChange={(e) => setAmount(e.target.value)}
                                    inputMode="decimal"
                                    hint={
                                        policy?.minimum
                                            ? `Part payments are accepted from $${policy.minimum} upwards.`
                                            : 'You can pay the full balance or part of it.'
                                    }
                                    required
                                />
                            )}

                            {/* [WP-39] The method is chosen here rather than on
                                the payment provider's page, because the fee
                                depends on it and has to be seen before it is
                                agreed to. */}
                            {cardsEnabled && (
                                <fieldset className="mb-4">
                                    <legend className="text-base font-medium text-gray-900">
                                        How would you like to pay?
                                    </legend>
                                    <div className="mt-2 space-y-2">
                                        {[
                                            {
                                                value: 'echeck',
                                                label: 'Bank account',
                                                note: 'No fee.',
                                            },
                                            {
                                                value: 'card',
                                                label: 'Debit or credit card',
                                                note:
                                                    feeOnCardCents > 0
                                                        ? `A $${cardConvenienceFee} fee is added.`
                                                        : 'No fee.',
                                            },
                                        ].map((option) => (
                                            <label
                                                key={option.value}
                                                className="flex min-h-touch items-center gap-3 rounded-md border border-gray-300 px-3 py-2 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50"
                                            >
                                                <input
                                                    type="radio"
                                                    name="method"
                                                    value={option.value}
                                                    checked={method === option.value}
                                                    onChange={() => setMethod(option.value)}
                                                    className="text-brand-600 focus:ring-brand-600"
                                                />
                                                <span className="text-base text-gray-900">
                                                    {option.label}
                                                </span>
                                                {/* Never colour alone: the fee
                                                    is stated in words. */}
                                                <span className="ml-auto text-sm text-gray-600">
                                                    {option.note}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                            )}

                            {feeCents > 0 && (
                                <dl className="mb-4 rounded-md border border-gray-200 bg-gray-50 p-3 text-base">
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-gray-700">Payment</dt>
                                        <dd>
                                            <Money value={amount || '0.00'} />
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-gray-700">Card fee</dt>
                                        <dd>
                                            <Money value={cardConvenienceFee} />
                                        </dd>
                                    </div>
                                    <div className="mt-1 flex justify-between gap-4 border-t border-gray-200 pt-1 font-semibold">
                                        <dt>Total charged today</dt>
                                        <dd>
                                            <Money value={fromCents(totalCents)} />
                                        </dd>
                                    </div>
                                </dl>
                            )}

                            {visibleSavedMethods.length > 0 && (
                                <div className="mb-4">
                                    <p className="text-base font-medium text-gray-900">
                                        {method === 'card' ? 'Saved cards' : 'Saved bank accounts'}
                                    </p>
                                    <ul className="mt-1 text-base text-gray-700">
                                        {visibleSavedMethods.map((saved) => (
                                            <li key={saved.id}>
                                                {saved.descriptor}
                                                {saved.needs_update && (
                                                    <span className="ml-2 text-sm text-overdue-fg">
                                                        needs updating
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                    <p className="mt-1 text-sm text-gray-600">
                                        You can choose a saved {method === 'card' ? 'card' : 'account'}, or
                                        enter a new one, on the secure payment page.
                                    </p>
                                </div>
                            )}

                            {/* UI §3.2: the ACH timing explainer sits with the
                                button, not in a footnote. It is the thing that
                                stops a second payment three days from now. */}
                            {/* [WP-39] A card clears the next business day and a
                                bank payment takes several, so one sentence
                                cannot serve both. The wording stays neutral
                                either way — a payment in flight is "processing",
                                never "failed". */}
                            <Alert tone="info" className="mb-4" title="How long this takes">
                                {method === 'card'
                                    ? 'Card payments usually clear the next business day. Your balance will show the payment as processing until then.'
                                    : 'Bank payments take 2–5 business days to clear. Your balance will show the payment as processing until then.'}
                            </Alert>

                            <button
                                type="submit"
                                disabled={submitting}
                                className="inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60 sm:w-auto"
                            >
                                {submitting ? 'Taking you to the secure page…' : 'Continue to secure payment'}
                            </button>

                            {/* AC-PAY-05, and the sentence has to name the right
                                thing — telling someone entering a card that we
                                never store their bank details reads as though
                                we were answering a different question. */}
                            <p className="mt-3 text-sm text-gray-600">
                                You will enter your {method === 'card' ? 'card details' : 'bank details'} on
                                our payment provider’s secure page. We never see or store them.
                            </p>
                        </form>
                    )}
                </>
            )}

            {/* Posted to Authorize.Net the moment a token arrives. */}
            {handoff && (
                <form ref={gatewayForm} method="post" action={handoff.redirect_url} className="hidden">
                    <input type="hidden" name="token" value={handoff.hosted_token} />
                </form>
            )}
        </PortalLayout>
    );
}
