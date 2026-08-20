import { Head, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import StatusBadge from '@/Components/StatusBadge';
import Alert from '@/Components/Alert';

/**
 * One notice, and who got it.  [AC-NTF-05, API-ADM-31]
 *
 * Everything AC-NTF-05 asks to still be visible afterwards: the date, the
 * audience, the per-recipient status, and the attachments.
 *
 * The body renders through `dangerouslySetInnerHTML` — one of only two places
 * in the product that does. It is safe because it was sanitised against an
 * allowlist before it was stored, not because anything happens here (TDD §6.2).
 */

const AUDIENCE_LABELS = {
    tenant: 'One resident',
    property: 'A property',
    selected: 'Selected residents',
    all: 'Every resident',
};

const NOTICE_PROSE =
    'text-base [&_a]:underline [&_h2]:mt-4 [&_h2]:text-lg [&_h2]:font-semibold ' +
    '[&_li]:ml-5 [&_li]:list-disc [&_p]:mb-3 [&_blockquote]:border-l-2 [&_blockquote]:pl-3';

export default function Show({ notice, recipients = [], attachments = [], flash = {} }) {
    const columns = [
        { key: 'tenant', header: 'Resident' },
        { key: 'email', header: 'Email', hideOnMobile: true, render: (r) => r.email ?? '—' },
        { key: 'sent_on', header: 'Sent', hideOnMobile: true, render: (r) => r.sent_on ?? '—' },
        {
            key: 'status',
            header: 'Status',
            align: 'right',
            render: (r) => (
                <span>
                    <StatusBadge status={r.status} />
                    {r.error && <span className="block text-sm text-gray-600">{r.error}</span>}
                </span>
            ),
        },
    ];

    const undeliverable = recipients.filter((r) => r.status === 'not_deliverable');

    return (
        <AdminLayout header={notice.subject}>
            <Head title={notice.subject} />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}

            {undeliverable.length > 0 && (
                <Alert tone="warning" className="mb-4" title="Some residents need a posted copy">
                    {undeliverable.length}{' '}
                    {undeliverable.length === 1 ? 'resident has' : 'residents have'} no email address
                    on file, so this did not reach them electronically.
                </Alert>
            )}

            <section className="mb-4 rounded-lg border border-gray-200 bg-white p-5">
                <dl className="mb-4 grid gap-1 text-base sm:grid-cols-2">
                    <div className="flex gap-2">
                        <dt className="text-gray-600">Sent</dt>
                        <dd>{notice.sent_on}</dd>
                    </div>
                    <div className="flex gap-2">
                        <dt className="text-gray-600">By</dt>
                        <dd>{notice.sent_by ?? '—'}</dd>
                    </div>
                    <div className="flex gap-2">
                        <dt className="text-gray-600">Audience</dt>
                        <dd>{AUDIENCE_LABELS[notice.audience_type] ?? notice.audience_type}</dd>
                    </div>
                    <div className="flex gap-2">
                        <dt className="text-gray-600">Recipients</dt>
                        <dd>{notice.recipient_count}</dd>
                    </div>
                </dl>

                {/* Sanitised server-side at composition. See HtmlSanitiser for
                    why the guarantee lives there and not here. */}
                <div
                    className={`border-t border-gray-100 pt-4 ${NOTICE_PROSE}`}
                    dangerouslySetInnerHTML={{ __html: notice.body }}
                />
            </section>

            {attachments.length > 0 && (
                <section className="mb-4 rounded-lg border border-gray-200 bg-white p-5">
                    <h2 className="mb-2 text-lg font-semibold text-gray-900">Attachments</h2>
                    <ul className="space-y-1 text-base">
                        {attachments.map((a) => (
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
                </section>
            )}

            <h2 className="mb-3 text-lg font-semibold text-gray-900">Delivery</h2>
            <DataTable columns={columns} rows={recipients} caption="Per-recipient delivery status" />

            <Link href="/admin/notices" className="mt-4 inline-block text-base underline">
                All notices
            </Link>
        </AdminLayout>
    );
}
