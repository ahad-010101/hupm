import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';
import EmptyState from '@/Components/EmptyState';
import FormField from '@/Components/FormField';
import StatusBadge from '@/Components/StatusBadge';

/**
 * The document vault.  [UI §3.7, API-ADM-26/27, FR-DOC-02]
 *
 * Version history is nested here rather than behind an endpoint of its own
 * (GAP-2, D-12) — a superseded lease is only interesting next to the one that
 * replaced it, and a separate screen would mean navigating away from the
 * comparison you opened it to make.
 *
 * Download links carry a five-minute signature. They are regenerated on every
 * page render, so a link copied out of the DOM stops working shortly after.
 */
export default function Index({
    documents = [],
    categories = {},
    tenants = [],
    filters = {},
    maxMegabytes = 25,
    flash = {},
    errors = {},
}) {
    const [expanded, setExpanded] = useState(null);
    const [replacing, setReplacing] = useState(null);

    const upload = useForm({
        tenant_id: filters.tenant_id ?? '',
        category: '',
        title: '',
        visible_to_tenant: true,
        file: null,
    });

    const replacement = useForm({ file: null });

    const filter = (params) => router.get('/admin/documents', params, { preserveScroll: true });

    return (
        <AdminLayout header="Documents">
            <Head title="Documents" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {errors.file && <Alert tone="error" className="mb-4" title="That file was not filed">{errors.file}</Alert>}

            <section className="mb-6 rounded-lg border border-gray-200 bg-white p-5">
                <h2 className="mb-3 text-lg font-semibold text-gray-900">File a document</h2>

                <div className="grid gap-x-4 sm:grid-cols-2">
                    <FormField label="Resident" error={upload.errors.tenant_id} required>
                        <select
                            value={upload.data.tenant_id}
                            onChange={(e) => upload.setData('tenant_id', e.target.value)}
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        >
                            <option value="">Choose a resident…</option>
                            {tenants.map((t) => (
                                <option key={t.id} value={t.id}>{t.name}</option>
                            ))}
                        </select>
                    </FormField>

                    <FormField label="What sort of document" error={upload.errors.category} required>
                        <select
                            value={upload.data.category}
                            onChange={(e) => upload.setData('category', e.target.value)}
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        >
                            <option value="">Choose…</option>
                            {Object.entries(categories).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                    </FormField>
                </div>

                <FormField
                    label="Title"
                    value={upload.data.title}
                    onChange={(e) => upload.setData('title', e.target.value)}
                    error={upload.errors.title}
                    hint="Optional. Defaults to the filename."
                    maxLength={200}
                />

                <div className="mb-4">
                    <label htmlFor="file" className="mb-1 block text-base font-medium text-gray-900">
                        File
                    </label>
                    <input
                        id="file"
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.docx"
                        onChange={(e) => upload.setData('file', e.target.files[0])}
                        className="block w-full text-base"
                    />
                    <p className="mt-1 text-sm text-gray-600">
                        PDF, JPG, PNG or DOCX, up to {maxMegabytes} MB. Photos are re-saved on upload,
                        which removes any location data the camera recorded.
                    </p>
                </div>

                <label className="mb-4 flex items-start gap-3 text-base">
                    <input
                        type="checkbox"
                        checked={upload.data.visible_to_tenant}
                        onChange={(e) => upload.setData('visible_to_tenant', e.target.checked)}
                        className="mt-1 size-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                    />
                    <span>
                        The resident can see this.
                        <span className="block text-sm text-gray-600">
                            Untick for an internal note kept on their record.
                        </span>
                    </span>
                </label>

                <button
                    type="button"
                    disabled={upload.processing || !upload.data.file || !upload.data.tenant_id || !upload.data.category}
                    onClick={() => upload.post('/admin/documents', {
                        forceFormData: true,
                        preserveScroll: true,
                        onSuccess: () => upload.reset('file', 'title'),
                    })}
                    className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
                >
                    {upload.processing ? 'Filing…' : 'File it'}
                </button>
            </section>

            <div className="mb-4 flex flex-wrap gap-2">
                <label htmlFor="filter-tenant" className="sr-only">Filter by resident</label>
                <select
                    id="filter-tenant"
                    value={filters.tenant_id ?? ''}
                    onChange={(e) => filter({ ...filters, tenant_id: e.target.value || undefined })}
                    className="min-h-touch rounded-md border-gray-300 text-base focus:border-brand-600 focus:ring-brand-600"
                >
                    <option value="">Every resident</option>
                    {tenants.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>

                <label htmlFor="filter-category" className="sr-only">Filter by type</label>
                <select
                    id="filter-category"
                    value={filters.category ?? ''}
                    onChange={(e) => filter({ ...filters, category: e.target.value || undefined })}
                    className="min-h-touch rounded-md border-gray-300 text-base focus:border-brand-600 focus:ring-brand-600"
                >
                    <option value="">Any type</option>
                    {Object.entries(categories).map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </select>
            </div>

            {documents.length === 0 ? (
                <EmptyState
                    title="Nothing filed yet."
                    description="Leases, HAP contracts, inspections and notices all live here."
                />
            ) : (
                <ul className="space-y-2">
                    {documents.map((document) => (
                        <li key={document.id} className="rounded-lg border border-gray-200 bg-white p-4">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <span className="text-base font-semibold text-gray-900">{document.title}</span>
                                <span className="text-sm text-gray-600">{document.category}</span>
                            </div>

                            <p className="mt-1 text-base text-gray-700">{document.tenant}</p>

                            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-600">
                                <span>v{document.version}</span>
                                <span>{document.added_on}</span>
                                <span>{document.size_kb} KB</span>
                                {/* Enough to compare two by eye; the whole hash is
                                    in the audit row. */}
                                <span className="font-mono">{document.sha256_short}…</span>
                                {document.is_signed && <StatusBadge status="signed" label="Signed" tone="settled" />}
                                {!document.visible_to_tenant && (
                                    <StatusBadge status="internal" label="Internal" tone="neutral" />
                                )}
                            </div>

                            <div className="mt-3 flex flex-wrap gap-3">
                                <a
                                    href={document.download_url}
                                    className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-3 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    Download
                                </a>

                                {!document.is_signed && (
                                    <button
                                        type="button"
                                        onClick={() => setReplacing(replacing === document.id ? null : document.id)}
                                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-3 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                    >
                                        Replace
                                    </button>
                                )}

                                {document.previous.length > 0 && (
                                    <button
                                        type="button"
                                        aria-expanded={expanded === document.id}
                                        onClick={() => setExpanded(expanded === document.id ? null : document.id)}
                                        className="inline-flex min-h-touch items-center text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                    >
                                        {document.previous.length} earlier{' '}
                                        {document.previous.length === 1 ? 'version' : 'versions'}
                                    </button>
                                )}
                            </div>

                            {replacing === document.id && (
                                <div className="mt-3 rounded-md border border-gray-200 bg-gray-50 p-3">
                                    <p className="mb-2 text-sm text-gray-700">
                                        The current file stays on record as an earlier version. Nothing
                                        is overwritten.
                                    </p>
                                    <input
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png,.docx"
                                        onChange={(e) => replacement.setData('file', e.target.files[0])}
                                        className="block w-full text-base"
                                    />
                                    <button
                                        type="button"
                                        disabled={replacement.processing || !replacement.data.file}
                                        onClick={() => replacement.post(`/admin/documents/${document.id}/replace`, {
                                            forceFormData: true,
                                            preserveScroll: true,
                                            onSuccess: () => { setReplacing(null); replacement.reset(); },
                                        })}
                                        className="mt-2 inline-flex min-h-touch items-center rounded-md bg-brand-600 px-3 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
                                    >
                                        {replacement.processing ? 'Uploading…' : 'Upload replacement'}
                                    </button>
                                </div>
                            )}

                            {expanded === document.id && (
                                <ul className="mt-3 space-y-2 border-l-2 border-gray-200 pl-3">
                                    {document.previous.map((version) => (
                                        <li key={version.id} className="text-base">
                                            <span className="text-gray-900">v{version.version}</span>
                                            <span className="ml-2 text-sm text-gray-600">
                                                {version.added_on} · {version.size_kb} KB ·{' '}
                                                <span className="font-mono">{version.sha256_short}…</span>
                                            </span>
                                            <a
                                                href={version.download_url}
                                                className="ml-2 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                            >
                                                Download
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </AdminLayout>
    );
}
