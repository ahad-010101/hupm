import { Head, Link } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import StatusBadge from '@/Components/StatusBadge';

/**
 * One document.  [API-POR-15/16]
 *
 * No inline viewer. A PDF rendered in-page on a phone is worse than the one
 * the operating system already knows how to open, and embedding it would mean
 * either a third-party script or trusting the browser with a file we have gone
 * to some trouble to keep behind a controller.
 */
export default function DocumentShow({ document }) {
    return (
        <PortalLayout header={document.title}>
            <Head title={document.title} />

            <section className="rounded-lg border border-gray-200 bg-white p-5">
                {document.is_signed && (
                    <StatusBadge status="signed" label="Signed" tone="settled" className="mb-3" />
                )}

                <dl className="space-y-1 text-base">
                    <div className="flex justify-between gap-4">
                        <dt className="text-gray-600">Type</dt>
                        <dd>{document.category}</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-gray-600">Added</dt>
                        <dd>{document.added_on}</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-gray-600">Size</dt>
                        <dd>{document.size_kb} KB</dd>
                    </div>
                </dl>

                <a
                    href={document.download_url}
                    className="mt-4 inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:w-auto"
                >
                    Download
                </a>

                <p className="mt-2 text-sm text-gray-600">
                    This link works for a few minutes. Reload the page if it stops.
                </p>
            </section>

            <Link
                href="/portal/documents"
                className="mt-4 inline-block text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            >
                All your documents
            </Link>
        </PortalLayout>
    );
}
