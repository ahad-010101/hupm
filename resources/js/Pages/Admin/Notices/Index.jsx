import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import Alert from '@/Components/Alert';

/**
 * Notices sent.  [UI §3.10, API-ADM-30]
 *
 * There is no edit and no delete control, because there is no route behind one
 * (AC-NTF-06). A notice is the record that a resident was told something.
 */

const AUDIENCE_LABELS = {
    tenant: 'One resident',
    property: 'A property',
    selected: 'Selected residents',
    all: 'Every resident',
};

export default function Index({ notices = [], flash = {} }) {
    const columns = [
        {
            key: 'subject',
            header: 'Subject',
            render: (n) => (
                <Link
                    href={`/admin/notices/${n.id}`}
                    className="font-medium underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    {n.subject}
                </Link>
            ),
        },
        {
            key: 'audience_type',
            header: 'Audience',
            hideOnMobile: true,
            render: (n) => AUDIENCE_LABELS[n.audience_type] ?? n.audience_type,
        },
        { key: 'sent_on', header: 'Sent', hideOnMobile: true },
        {
            key: 'recipients',
            header: 'Delivery',
            align: 'right',
            render: (n) => (
                <span>
                    {n.delivered} of {n.recipients}
                    {n.problems > 0 && (
                        // Surfaced rather than buried: the resident who did not
                        // get a notice is the one it mattered to.
                        <span className="block text-sm font-medium text-overdue-fg">
                            {n.problems} need attention
                        </span>
                    )}
                </span>
            ),
        },
    ];

    return (
        <AdminLayout header="Notices">
            <Head title="Notices" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            <div className="mb-4 flex justify-end">
                <Link
                    href="/admin/notices/new"
                    className="inline-flex min-h-touch items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    Write a notice
                </Link>
            </div>

            <DataTable
                columns={columns}
                rows={notices}
                caption="Notices sent"
                empty={
                    <EmptyState
                        title="No notices yet."
                        description="Rent increases, inspections and service interruptions go out from here."
                    />
                }
            />
        </AdminLayout>
    );
}
