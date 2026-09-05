import { Head, Link, useForm } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import Alert from '@/Components/Alert';
import EmptyState from '@/Components/EmptyState';
import FormField from '@/Components/FormField';
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
export default function Documents({ documents = [], maxMb = 25, flash = {}, errors = {} }) {
    // [WP-42] Sending something in. A real multipart form: the file never
    // touches this page's JavaScript, and the browser handles the upload.
    const upload = useForm({ title: '', file: null });

    const send = (e) => {
        e.preventDefault();
        upload.post('/portal/documents', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => upload.reset(),
        });
    };

    return (
        <PortalLayout header="Your documents">
            <Head title="Your documents" />

            {flash.status && (
                <Alert tone="success" className="mb-4">
                    {flash.status}
                </Alert>
            )}

            <form
                onSubmit={send}
                encType="multipart/form-data"
                className="mb-6 rounded-lg border border-gray-200 bg-white p-4"
            >
                <h2 className="text-lg font-semibold text-gray-900">Send us a document</h2>
                <p className="mt-1 text-base text-gray-700">
                    Insurance, proof of income, a photograph of something that needs fixing — anything
                    the office has asked you for.
                </p>

                <div className="mt-4">
                    <FormField
                        label="What is it?"
                        value={upload.data.title}
                        onChange={(e) => upload.setData('title', e.target.value)}
                        error={upload.errors.title || errors.title}
                        maxLength={200}
                        hint="For example, “Renters insurance” or “Proof of income — August”."
                        required
                    />

                    <FormField label="Choose a file" error={upload.errors.file || errors.file} required>
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.docx"
                            onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                            required
                            className="block w-full text-base file:mr-3 file:min-h-touch file:rounded-md file:border-0 file:bg-brand-600 file:px-4 file:text-base file:font-semibold file:text-white hover:file:bg-brand-700"
                        />
                        <p className="mt-1 text-sm text-gray-600">
                            PDF, photo or Word document, up to {maxMb}MB.
                        </p>
                    </FormField>
                </div>

                <button
                    type="submit"
                    disabled={upload.processing}
                    className="mt-2 inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60 sm:w-auto"
                >
                    {upload.processing ? 'Sending…' : 'Send to the office'}
                </button>
            </form>

            {documents.length === 0 ? (
                <EmptyState
                    title="Nothing here yet."
                    description="Your lease and any notices we send you will appear here, along with anything you send us."
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
                                {/* Which direction it came from, in words —
                                    never colour alone (UI §9). */}
                                {document.sent_by_resident && (
                                    <StatusBadge status="sent" label="You sent this" tone="neutral" />
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
