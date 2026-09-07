import { Head, Link } from '@inertiajs/react';
import HaLayout from '@/Layouts/HaLayout';
import Alert from '@/Components/Alert';
import Money from '@/Components/Money';

/**
 * Back from the gateway.  [WP-43]
 *
 * Deliberately does not claim the payment has cleared. A bank transfer takes
 * days and can still be returned — the balance moves on settlement and never
 * before (I-6), and saying otherwise here would be the one screen that lies.
 */
export default function PayResult({ cancelled = false, total }) {
    return (
        <HaLayout total={total} header="Payment">
            <Head title="Payment" />

            {cancelled ? (
                <Alert tone="info" title="Payment cancelled">
                    You came back without paying. Nothing has been charged, and the amount
                    outstanding is unchanged at <Money value={total} />.
                </Alert>
            ) : (
                <Alert tone="info" title="Thank you — this is processing">
                    Bank payments take 2–5 business days to clear. The amount will show as
                    outstanding until it settles, then update on its own.
                </Alert>
            )}

            <Link
                href="/agency"
                className="mt-4 inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            >
                Back to your leases
            </Link>
        </HaLayout>
    );
}
