import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import FormField from '@/Components/FormField';
import Alert from '@/Components/Alert';

/** Housing authority create/edit.  [FR-REG-01, GATE Q-2] */
export default function Form({ authority }) {
    const editing = Boolean(authority);

    const { data, setData, post, patch, processing, errors } = useForm({
        name: authority?.name ?? '',
        contact_name: authority?.contact_name ?? '',
        contact_email: authority?.contact_email ?? '',
        contact_phone: authority?.contact_phone ?? '',
        remittance_type: authority?.remittance_type ?? 'per_tenant',
    });

    const submit = (e) => {
        e.preventDefault();
        editing ? patch(`/admin/housing-authorities/${authority.id}`) : post('/admin/housing-authorities');
    };

    return (
        <AdminLayout header={editing ? `Edit ${authority.name}` : 'Add housing authority'}>
            <Head title={editing ? 'Edit housing authority' : 'Add housing authority'} />

            <form onSubmit={submit} noValidate className="max-w-2xl">
                <FormField
                    label="Authority name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    error={errors.name}
                    maxLength={150}
                    required
                />

                <div className="grid gap-x-4 sm:grid-cols-2">
                    <FormField
                        label="Contact name"
                        value={data.contact_name}
                        onChange={(e) => setData('contact_name', e.target.value)}
                        error={errors.contact_name}
                        maxLength={150}
                    />
                    <FormField
                        label="Contact phone"
                        value={data.contact_phone}
                        onChange={(e) => setData('contact_phone', e.target.value)}
                        error={errors.contact_phone}
                        maxLength={30}
                    />
                </div>

                <FormField
                    label="Contact email"
                    type="email"
                    value={data.contact_email}
                    onChange={(e) => setData('contact_email', e.target.value)}
                    error={errors.contact_email}
                    maxLength={255}
                />

                <FormField
                    label="How does this authority pay?"
                    error={errors.remittance_type}
                    hint="Per tenant means one payment per resident. Lump sum means a single payment covering several, which has to be split by hand."
                    required
                >
                    <select
                        value={data.remittance_type}
                        onChange={(e) => setData('remittance_type', e.target.value)}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        <option value="per_tenant">Per tenant</option>
                        <option value="lump_sum">Lump sum</option>
                    </select>
                </FormField>

                {data.remittance_type === 'lump_sum' && (
                    <Alert tone="warning" className="mb-4" title="This needs confirming with the authority">
                        Lump-sum payments must be allocated across residents by hand. Make sure this
                        matches how they actually remit before relying on it.
                    </Alert>
                )}

                <div className="mt-2 flex flex-col-reverse gap-3 sm:flex-row">
                    <Link
                        href="/admin/housing-authorities"
                        className="inline-flex min-h-touch items-center justify-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="min-h-touch rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : editing ? 'Save changes' : 'Add authority'}
                    </button>
                </div>
            </form>
        </AdminLayout>
    );
}
