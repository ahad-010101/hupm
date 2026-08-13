import { Head } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';

/** WP-17 replaces this with the viewer and a short-lived signed download URL. */
export default function DocumentShow({ document }) {
    return (
        <PortalLayout header={document.title}>
            <Head title={document.title} />

            <dl className="rounded-lg border border-gray-200 bg-white p-4">
                <div className="flex justify-between gap-4 border-b border-gray-100 py-2">
                    <dt className="text-sm text-gray-600">Type</dt>
                    <dd className="text-base">{document.category}</dd>
                </div>
                <div className="flex justify-between gap-4 py-2">
                    <dt className="text-sm text-gray-600">Added</dt>
                    <dd className="text-base">{document.created_at}</dd>
                </div>
            </dl>
        </PortalLayout>
    );
}
