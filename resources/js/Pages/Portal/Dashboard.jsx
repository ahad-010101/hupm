import { Head } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import Alert from '@/Components/Alert';
import Money from '@/Components/Money';

/**
 * Tenant dashboard.  [UI §3.1, AC-LED-02]
 *
 * INVARIANT I-4: the Housing Authority portion is not filtered out here — it
 * never arrives. The controller sends the tenant balance alone, so there is
 * nothing in the props for a future change to accidentally reveal.
 *
 * WP-15A builds the rest of this screen against UI §3.1.
 */
export default function Dashboard({ balance = null, pending = null }) {
    const hasPending = pending && pending !== '0.00';
    const owes = balance && !balance.startsWith('-') && balance !== '0.00';

    return (
        <PortalLayout header="Your account" balance={balance} pendingAmount={hasPending ? pending : null}>
            <Head title="Your account" />

            <section className="rounded-lg border border-gray-200 bg-white p-6">
                <h2 className="text-sm text-gray-600">Current balance</h2>
                <p className="mt-1 text-3xl">
                    <Money value={balance} balance />
                </p>

                {hasPending && (
                    // The critical UX note in UI §3.1: ACH takes 2–5 business
                    // days, and a tenant who cannot see their payment in flight
                    // pays a second time.
                    <p className="mt-2 text-base text-gray-700">
                        <Money value={pending} /> is processing and will come off this balance once it
                        clears with your bank.
                    </p>
                )}

                {!owes && !hasPending && (
                    <p className="mt-2 text-base text-gray-700">You are all paid up.</p>
                )}
            </section>

            <Alert tone="info" className="mt-4" title="More coming to this page">
                Your payment history and the option to pay online arrive shortly. Contact the office
                in the meantime.
            </Alert>
        </PortalLayout>
    );
}
