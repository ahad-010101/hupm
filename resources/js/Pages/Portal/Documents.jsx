import { Head, Link } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';

/**
 * A resident's own documents.  [UI §3.7, API-POR-15]
 *
 * The download link is a real anchor carrying a five-minute signature, not a
 * fetch: the browser saves the file itself, which is what someone needs when
 * they are asked to produce their lease.
 *
 * Only documents marked visible reach this list, and the query is scoped from
 * the session rather than from anything in the URL (BR-20).
 */
export default function Documents({ documents = [] }) {
    return (
        <PortalLayout header="Your documents">
            <Head title="Your documents" />

            {documents.length === 0 ? (
                <EmptyState
                    title="Nothing here yet."
                    description="Your lease and any notices we send you will appear here."
                />
            ) : (
                <ul className="space-y-2">
                    {documents.map((document) => (
                        <li key={document.id} className="rounded-lg border border-gray-200 bg-white p-4">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <Link
                                    href={`/portal/documents/${document.id}`}
                                    className="text-base font-medium text-gray-900 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    {document.title}
                                </Link>
                                {document.is_signed && (
                                    <StatusBadge status="signed" label="Signed" tone="settled" />
                                )}
                            </div>

                            <p className="mt-1 text-sm text-gray-600">
                                {document.category} · {document.added_on} · {document.size_kb} KB
                            </p>

                            <a
                                href={document.download_url}
                                className="mt-3 inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            >
                                Download
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
