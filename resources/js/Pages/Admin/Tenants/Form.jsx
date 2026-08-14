import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import FormField from '@/Components/FormField';
import Alert from '@/Components/Alert';

/** Tenant create/edit.  [FR-REG-02, Q-4] */
export default function Form({ tenant }) {
    const editing = Boolean(tenant);

    const { data, setData, post, patch, processing, errors } = useForm({
        first_name: tenant?.first_name ?? '',
        last_name: tenant?.last_name ?? '',
        email: tenant?.email ?? '',
        phone: tenant?.phone ?? '',
        emergency_contact_name: tenant?.emergency_contact_name ?? '',
        emergency_contact_phone: tenant?.emergency_contact_phone ?? '',
        notes: tenant?.notes ?? '',
        status: tenant?.status ?? 'active',
    });

    const submit = (e) => {
        e.preventDefault();
        editing ? patch(`/admin/tenants/${tenant.id}`) : post('/admin/tenants');
    };

    return (
        <AdminLayout header={editing ? `Edit ${tenant.first_name} ${tenant.last_name}` : 'Add tenant'}>
            <Head title={editing ? 'Edit tenant' : 'Add tenant'} />

            <form onSubmit={submit} noValidate className="max-w-3xl">
                <div className="grid gap-x-4 sm:grid-cols-2">
                    <FormField
                        label="First name"
                        value={data.first_name}
                        onChange={(e) => setData('first_name', e.target.value)}
                        error={errors.first_name}
                        maxLength={100}
                        required
                    />
                    <FormField
                        label="Last name"
                        value={data.last_name}
                        onChange={(e) => setData('last_name', e.target.value)}
                        error={errors.last_name}
                        maxLength={100}
                        required
                    />
                </div>

                <FormField
                    label="Email address"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    error={errors.email}
                    maxLength={255}
                    hint="Optional. Without one, this tenant cannot have a portal account and will not receive any email."
                />

                {!data.email && (
                    <Alert tone="warning" className="mb-4" title="This tenant will have no online account">
                        Rent reminders, receipts and notices cannot reach them. They will need to be
                        served by phone or post, and payments recorded on their behalf.
                    </Alert>
                )}

                <FormField
                    label="Phone"
                    value={data.phone}
                    onChange={(e) => setData('phone', e.target.value)}
                    error={errors.phone}
                    maxLength={30}
                />

                <fieldset className="mb-2">
                    <legend className="mb-1 text-base font-medium text-gray-900">Emergency contact</legend>
                    <div className="grid gap-x-4 sm:grid-cols-2">
                        <FormField
                            label="Name"
                            value={data.emergency_contact_name}
                            onChange={(e) => setData('emergency_contact_name', e.target.value)}
                            error={errors.emergency_contact_name}
                            maxLength={150}
                        />
                        <FormField
                            label="Phone"
                            value={data.emergency_contact_phone}
                            onChange={(e) => setData('emergency_contact_phone', e.target.value)}
                            error={errors.emergency_contact_phone}
                            maxLength={30}
                        />
                    </div>
                </fieldset>

                <FormField label="Status" error={errors.status} required>
                    <select
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        <option value="active">Active</option>
                        <option value="former">Former</option>
                    </select>
                </FormField>

                <FormField label="Notes" error={errors.notes} hint="Internal only. Never shown to the tenant.">
                    <textarea
                        value={data.notes}
                        onChange={(e) => setData('notes', e.target.value)}
                        rows={3}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    />
                </FormField>

                <div className="mt-2 flex flex-col-reverse gap-3 sm:flex-row">
                    <Link
                        href={editing ? `/admin/tenants/${tenant.id}` : '/admin/tenants'}
                        className="inline-flex min-h-touch items-center justify-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="min-h-touch rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : editing ? 'Save changes' : 'Add tenant'}
                    </button>
                </div>
            </form>
        </AdminLayout>
    );
}
