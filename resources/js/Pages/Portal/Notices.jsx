import { Head } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import EmptyState from '@/Components/EmptyState';

/**
 * Notices a resident has received.  [FR-NTF-02, API-POR-20]
 *
 * The body renders through `dangerouslySetInnerHTML`. It is safe because it was
 * sanitised against an allowlist before being stored — the guarantee is on the
 * way in, not here (TDD §6.2).
 *
 * Shown in full rather than as a list of links: a notice is usually two
 * paragraphs, and making someone tap through to read what their landlord sent
 * them is a small unkindness on a phone.
 */

const NOTICE_PROSE =
    'text-base [&_a]:underline [&_h2]:mt-3 [&_h2]:text-base [&_h2]:font-semibold ' +
    '[&_li]:ml-5 [&_li]:list-disc [&_p]:mb-3 [&_blockquote]:border-l-2 [&_blockquote]:pl-3';

export default function Notices({ notices = [] }) {
    return (
        <PortalLayout header="Notices">
            <Head title="Notices" />

            {notices.length === 0 ? (
                <EmptyState
                    title="Nothing here yet."
                    description="Messages from your property manager appear here and stay on your record."
                />
            ) : (
                <ul className="space-y-4">
                    {notices.map((notice) => (
                        <li key={notice.id} className="rounded-lg border border-gray-200 bg-white p-5">
                            <h2 className="text-lg font-semibold text-gray-900">{notice.subject}</h2>
                            <p className="mt-1 text-sm text-gray-600">{notice.sent_on}</p>

                            <div
                                className={`mt-3 ${NOTICE_PROSE}`}
                                dangerouslySetInnerHTML={{ __html: notice.body }}
                            />

                            {notice.attachments.length > 0 && (
                                <ul className="mt-3 space-y-1 border-t border-gray-100 pt-3 text-base">
                                    {notice.attachments.map((a) => (
                                        <li key={a.id}>
                                            <a
                                                href={a.url}
                                                className="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                            >
                                                {a.filename}
                                            </a>
                                            <span className="ml-2 text-sm text-gray-600">{a.size_kb} KB</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
