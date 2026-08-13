import { Head, Link } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';

/** Tenant document list. WP-17 adds storage, versioning and signed downloads. */
export default function Documents({ documents = [] }) {
    const columns = [
        {
            key: 'title',
            header: 'Document',
            render: (row) => (
                <Link
                    href={`/portal/documents/${row.id}`}
                    className="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    {row.title}
                </Link>
            ),
        },
        { key: 'category', header: 'Type' },
        { key: 'created_at', header: 'Added', align: 'right' },
    ];

    return (
        <PortalLayout header="Your documents">
            <Head title="Your documents" />

            <DataTable
                columns={columns}
                rows={documents}
                caption="Your documents"
                empty={
                    <EmptyState
                        title="No documents yet."
                        description="Your lease will appear here once uploaded."
                    />
                }
            />
        </PortalLayout>
    );
}
