import { Head } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import Alert from '@/Components/Alert';

/**
 * Tenant dashboard shell. WP-15A fills this in against UI §3.1 once the ledger
 * exists — balance card, next-due card, recent activity.
 *
 * INVARIANT I-4: whatever lands here, the Housing Authority portion never does.
 */
export default function Dashboard() {
    return (
        <PortalLayout header="Your account">
            <Head title="Your account" />

            <Alert tone="info" title="Your account is set up">
                Your balance and payment history will appear here. If you need anything in the
                meantime, contact the office.
            </Alert>
        </PortalLayout>
    );
}
