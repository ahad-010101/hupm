import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';

/**
 * Admin dashboard shell. WP-26 builds the real one against UI §3.7 — exceptions
 * panel, reconciliation freshness, delinquency counts.
 */
export default function Dashboard() {
    return (
        <AdminLayout header="Dashboard">
            <Head title="Dashboard" />

            <Alert tone="info" title="Portfolio management">
                Properties, tenants and leases arrive in WP-06 and WP-07. The ledger follows.
            </Alert>
        </AdminLayout>
    );
}
