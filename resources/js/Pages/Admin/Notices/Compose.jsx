import { useEffect, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';
import FormField from '@/Components/FormField';

/**
 * Compose a notice.  [UI §3.10, FR-NTF-02]
 *
 * The recipient count is live and comes from the server, resolved by the same
 * method that resolves the send — so the number shown is the number that will
 * be written, not a client-side approximation of it.
 *
 * **Sending to everyone requires typing the count.** A mis-clicked audience
 * selector is otherwise indistinguishable from a deliberate all-residents send
 * until the replies start arriving.
 *
 * The body is plain HTML typed by an admin and sanitised server-side against
 * an allowlist before it is stored. There is no rich-text editor here on
 * purpose: a WYSIWYG would be a large dependency producing markup the
 * allowlist mostly strips, for a letter that wants a paragraph and a link.
 */

const AUDIENCES = [
    { value: 'tenant', label: 'One resident' },
    { value: 'property', label: 'Everyone at a property' },
    { value: 'selected', label: 'Selected residents' },
    { value: 'all', label: 'Every resident' },
];

export default function Compose({ tenants = [], properties = [], maxAttachments = 3, maxMegabytes = 10 }) {
    const [audience, setAudience] = useState({ count: null, without_email: 0 });
    const [checking, setChecking] = useState(false);

    const { data, setData, post, processing, errors, transform } = useForm({
        subject: '',
        body: '',
        audience_type: 'tenant',
        audience_ref: [],
        confirm_count: '',
        attachments: [],
    });

    // Re-resolved whenever the audience changes. Debounced lightly so dragging
    // through a multi-select does not fire a request per keystroke.
    useEffect(() => {
        let cancelled = false;
        const timer = setTimeout(async () => {
            setChecking(true);
            try {
                const { data: result } = await window.axios.post('/admin/notices/audience', {
                    audience_type: data.audience_type,
                    audience_ref: data.audience_ref,
                });
                if (!cancelled) setAudience(result);
            } catch {
                if (!cancelled) setAudience({ count: null, without_email: 0 });
            } finally {
                if (!cancelled) setChecking(false);
            }
        }, 250);

        return () => { cancelled = true; clearTimeout(timer); };
    }, [data.audience_type, data.audience_ref]);

    const needsTypedConfirmation = data.audience_type === 'all';
    const confirmed = !needsTypedConfirmation
        || String(data.confirm_count).trim() === String(audience.count);

    const submit = (e) => {
        e.preventDefault();
        transform((form) => ({ ...form, confirm_count: form.confirm_count || null }));
        post('/admin/notices', { forceFormData: true });
    };

    const toggleRef = (id) => {
        const current = data.audience_ref.map(String);
        setData('audience_ref', current.includes(String(id))
            ? current.filter((v) => v !== String(id))
            : [...current, String(id)]);
    };

    return (
        <AdminLayout header="Write a notice">
            <Head title="Write a notice" />

            <form onSubmit={submit} noValidate className="max-w-3xl">
                <FormField label="Who is this for" error={errors.audience_type} required>
                    <select
                        value={data.audience_type}
                        onChange={(e) => { setData('audience_type', e.target.value); setData('audience_ref', []); }}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        {AUDIENCES.map((a) => <option key={a.value} value={a.value}>{a.label}</option>)}
                    </select>
                </FormField>

                {data.audience_type === 'tenant' && (
                    <FormField label="Resident" error={errors.audience_ref} required>
                        <select
                            value={data.audience_ref[0] ?? ''}
                            onChange={(e) => setData('audience_ref', e.target.value ? [e.target.value] : [])}
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        >
                            <option value="">Choose a resident…</option>
                            {tenants.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}{t.has_email ? '' : ' (no email on file)'}
                                </option>
                            ))}
                        </select>
                    </FormField>
                )}

                {data.audience_type === 'property' && (
                    <fieldset className="mb-4">
                        <legend className="mb-1 text-base font-medium text-gray-900">Properties</legend>
                        <div className="max-h-56 space-y-1 overflow-y-auto rounded-md border border-gray-200 p-3">
                            {properties.map((p) => (
                                <label key={p.id} className="flex items-center gap-2 text-base">
                                    <input
                                        type="checkbox"
                                        checked={data.audience_ref.map(String).includes(String(p.id))}
                                        onChange={() => toggleRef(p.id)}
                                        className="size-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                                    />
                                    {p.name}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                )}

                {data.audience_type === 'selected' && (
                    <fieldset className="mb-4">
                        <legend className="mb-1 text-base font-medium text-gray-900">Residents</legend>
                        <div className="max-h-56 space-y-1 overflow-y-auto rounded-md border border-gray-200 p-3">
                            {tenants.map((t) => (
                                <label key={t.id} className="flex items-center gap-2 text-base">
                                    <input
                                        type="checkbox"
                                        checked={data.audience_ref.map(String).includes(String(t.id))}
                                        onChange={() => toggleRef(t.id)}
                                        className="size-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                                    />
                                    {t.name}
                                    {!t.has_email && <span className="text-sm text-gray-600">no email on file</span>}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                )}

                {/* The live count, and the number of people it will not reach.
                    Better known before sending than discovered afterwards. */}
                <div aria-live="polite" className="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-base">
                    {checking ? (
                        <span className="text-gray-600">Working out who that is…</span>
                    ) : audience.count === null ? (
                        <span className="text-gray-600">Choose an audience.</span>
                    ) : (
                        <>
                            <strong>{audience.count}</strong>{' '}
                            {audience.count === 1 ? 'resident' : 'residents'} will receive this.
                            {audience.without_email > 0 && (
                                <span className="ml-2 font-medium text-overdue-fg">
                                    {audience.without_email} of them {audience.without_email === 1 ? 'has' : 'have'} no
                                    email on file and will need a posted copy.
                                </span>
                            )}
                        </>
                    )}
                </div>

                <FormField
                    label="Subject"
                    value={data.subject}
                    onChange={(e) => setData('subject', e.target.value)}
                    error={errors.subject}
                    maxLength={150}
                    required
                />

                <FormField label="Message" error={errors.body} required>
                    <textarea
                        value={data.body}
                        onChange={(e) => setData('body', e.target.value)}
                        rows={10}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        placeholder={'<p>Dear resident,</p>\n<p>The water will be off on Tuesday between 9am and 1pm.</p>'}
                    />
                </FormField>
                <p className="-mt-3 mb-4 text-sm text-gray-600">
                    Basic HTML is allowed: paragraphs, bold, italics, lists, headings and links.
                    Anything else is stripped when it is saved.
                </p>

                <div className="mb-4">
                    <label htmlFor="attachments" className="mb-1 block text-base font-medium text-gray-900">
                        Attachments
                    </label>
                    <input
                        id="attachments"
                        type="file"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png"
                        onChange={(e) => setData('attachments', Array.from(e.target.files).slice(0, maxAttachments))}
                        className="block w-full text-base"
                    />
                    <p className="mt-1 text-sm text-gray-600">
                        Up to {maxAttachments} files, PDF, JPG or PNG, {maxMegabytes} MB each.
                    </p>
                    {errors.attachments && (
                        <p className="mt-1 text-sm text-overdue-fg">{errors.attachments}</p>
                    )}
                </div>

                {needsTypedConfirmation && (
                    <Alert tone="warning" className="mb-4" title="This goes to every resident">
                        <p className="mb-2">
                            Type <strong>{audience.count}</strong> below to confirm.
                        </p>
                        <input
                            inputMode="numeric"
                            value={data.confirm_count}
                            onChange={(e) => setData('confirm_count', e.target.value)}
                            aria-label={`Type ${audience.count} to confirm`}
                            className="min-h-touch w-32 rounded-md border-gray-300 text-base focus:border-brand-600 focus:ring-brand-600"
                        />
                        {errors.confirm_count && (
                            <p className="mt-1 text-sm text-overdue-fg">{errors.confirm_count}</p>
                        )}
                    </Alert>
                )}

                <p className="mb-4 text-sm text-gray-600">
                    A sent notice cannot be edited or deleted. It is the record that a resident was
                    told something.
                </p>

                <div className="flex gap-3">
                    <button
                        type="submit"
                        disabled={processing || !confirmed || !audience.count}
                        className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
                    >
                        {processing ? 'Sending…' : 'Send notice'}
                    </button>
                    <Link
                        href="/admin/notices"
                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </AdminLayout>
    );
}
