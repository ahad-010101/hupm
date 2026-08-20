import { useMemo, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';
import EmptyState from '@/Components/EmptyState';
import FormField from '@/Components/FormField';
import StatusBadge from '@/Components/StatusBadge';

/**
 * Signature requests.  [API-ADM-28/29, AC-SIG-03/05]
 *
 * The integrity check is on this screen rather than in a console command,
 * because "is this signature still good" gets asked under pressure — usually
 * the day before a hearing. It is computed on every render from the bytes on
 * disk; a cached answer would be about the file at some earlier moment.
 *
 * There is no delete control, because there is no route behind one. A signed
 * document is superseded, never removed (BR-27, AC-SIG-05).
 */
export default function Index({ requests = [], documents = [], signers = [], flash = {}, errors = {} }) {
    const [documentId, setDocumentId] = useState('');

    const form = useForm({ document_id: '', user_id: '', expires_at: '' });

    // A document belongs to one resident, so the signer list narrows to the
    // people who could legitimately sign it.
    const chosen = useMemo(
        () => documents.find((d) => String(d.id) === String(documentId)),
        [documents, documentId],
    );

    const eligible = useMemo(
        () => (chosen ? signers.filter((s) => String(s.tenant_id) === String(chosen.tenant_id)) : []),
        [signers, chosen],
    );

    const broken = requests.filter((r) => r.integrity && !r.integrity.valid);

    return (
        <AdminLayout header="Signatures">
            <Head title="Signatures" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {errors.document_id && <Alert tone="error" className="mb-4">{errors.document_id}</Alert>}

            {broken.length > 0 && (
                <Alert tone="error" className="mb-4" title="A signed document no longer matches what was signed">
                    {/* AC-SIG-03. This is the loudest thing on the screen for a
                        reason: a mismatch means the signature cannot be relied
                        on, and nobody should find that out from a lawyer. */}
                    {broken.length} {broken.length === 1 ? 'signature has' : 'signatures have'} failed
                    verification. Investigate before relying on {broken.length === 1 ? 'it' : 'them'}.
                </Alert>
            )}

            <section className="mb-6 rounded-lg border border-gray-200 bg-white p-5">
                <h2 className="mb-3 text-lg font-semibold text-gray-900">Ask for a signature</h2>

                <div className="grid gap-x-4 sm:grid-cols-2">
                    <FormField label="Document" error={form.errors.document_id} required>
                        <select
                            value={form.data.document_id}
                            onChange={(e) => {
                                form.setData('document_id', e.target.value);
                                form.setData('user_id', '');
                                setDocumentId(e.target.value);
                            }}
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        >
                            <option value="">Choose a document…</option>
                            {documents.map((d) => (
                                <option key={d.id} value={d.id}>{d.title} — {d.tenant}</option>
                            ))}
                        </select>
                    </FormField>

                    <FormField label="Signer" error={form.errors.user_id} required>
                        <select
                            value={form.data.user_id}
                            onChange={(e) => form.setData('user_id', e.target.value)}
                            disabled={!chosen}
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600 disabled:bg-gray-100"
                        >
                            <option value="">
                                {chosen ? 'Choose a signer…' : 'Choose a document first'}
                            </option>
                            {eligible.map((s) => (
                                <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                        </select>
                    </FormField>
                </div>

                {chosen && eligible.length === 0 && (
                    <Alert tone="warning" className="mb-4">
                        That resident has no portal login, so nobody can sign electronically. Invite
                        them from their tenant record first, or use a paper copy.
                    </Alert>
                )}

                <FormField
                    label="Expires"
                    type="datetime-local"
                    value={form.data.expires_at}
                    onChange={(e) => form.setData('expires_at', e.target.value)}
                    error={form.errors.expires_at}
                    hint="Optional. After this the link stops working and nothing can be signed with it."
                />

                <button
                    type="button"
                    disabled={form.processing || !form.data.document_id || !form.data.user_id}
                    onClick={() => form.post('/admin/signatures', {
                        preserveScroll: true,
                        onSuccess: () => { form.reset(); setDocumentId(''); },
                    })}
                    className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
                >
                    {form.processing ? 'Sending…' : 'Send the request'}
                </button>
            </section>

            {requests.length === 0 ? (
                <EmptyState
                    title="No signature requests yet."
                    description="File a document in the vault, then ask the resident to sign it."
                />
            ) : (
                <ul className="space-y-2">
                    {requests.map((r) => (
                        <li key={r.id} className="rounded-lg border border-gray-200 bg-white p-4">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <span className="text-base font-semibold text-gray-900">{r.document}</span>
                                <StatusBadge
                                    status={r.status}
                                    label={r.expired && r.status !== 'signed' ? 'Expired' : undefined}
                                    tone={r.expired && r.status !== 'signed' ? 'overdue' : undefined}
                                />
                            </div>

                            <p className="mt-1 text-base text-gray-700">
                                {r.tenant} · {r.signer}
                            </p>

                            <p className="mt-1 text-sm text-gray-600">
                                Sent {r.sent_on}
                                {r.signed_on && <> · signed {r.signed_on}</>}
                            </p>

                            {r.integrity && (
                                <p className="mt-2 text-sm">
                                    {r.integrity.valid ? (
                                        <span className="font-medium text-credit-fg">
                                            Verified — the file still matches what was signed.
                                        </span>
                                    ) : (
                                        <span className="font-medium text-overdue-fg">
                                            {r.integrity.reason}
                                        </span>
                                    )}
                                </p>
                            )}

                            {r.signed_document_id && (
                                <Link
                                    href={`/admin/documents?tenant_id=${r.tenant_id}`}
                                    className="mt-2 inline-block text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    Open the executed copy in the vault
                                </Link>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </AdminLayout>
    );
}
