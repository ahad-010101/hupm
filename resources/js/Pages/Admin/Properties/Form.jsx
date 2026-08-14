import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import FormField from '@/Components/FormField';

/** Property create/edit.  [FR-REG-01, AC-REG-02] */
export default function Form({ property }) {
    const editing = Boolean(property);

    const { data, setData, post, patch, processing, errors } = useForm({
        name: property?.name ?? '',
        street_address: property?.street_address ?? '',
        city: property?.city ?? '',
        state: property?.state ?? 'GA',
        zip: property?.zip ?? '',
        county: property?.county ?? '',
        notes: property?.notes ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        editing ? patch(`/admin/properties/${property.id}`) : post('/admin/properties');
    };

    return (
        <AdminLayout header={editing ? `Edit ${property.name}` : 'Add property'}>
            <Head title={editing ? 'Edit property' : 'Add property'} />

            {/* Single column below 1024px, two where it helps (UI §6). */}
            <form onSubmit={submit} noValidate className="max-w-3xl">
                <FormField
                    label="Property name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    error={errors.name}
                    maxLength={150}
                    required
                />

                <FormField
                    label="Street address"
                    value={data.street_address}
                    onChange={(e) => setData('street_address', e.target.value)}
                    error={errors.street_address}
                    maxLength={255}
                    required
                />

                <div className="grid gap-x-4 sm:grid-cols-2">
                    <FormField
                        label="City"
                        value={data.city}
                        onChange={(e) => setData('city', e.target.value)}
                        error={errors.city}
                        maxLength={100}
                        required
                    />

                    <FormField
                        label="County"
                        value={data.county}
                        onChange={(e) => setData('county', e.target.value)}
                        error={errors.county}
                        maxLength={100}
                        hint="Optional."
                    />

                    <FormField
                        label="State"
                        value={data.state}
                        onChange={(e) => setData('state', e.target.value.toUpperCase())}
                        error={errors.state}
                        maxLength={2}
                        required
                    />

                    <FormField
                        label="ZIP code"
                        value={data.zip}
                        onChange={(e) => setData('zip', e.target.value)}
                        error={errors.zip}
                        inputMode="numeric"
                        maxLength={5}
                        hint="Five digits. This determines which weather alerts residents receive."
                        required
                    />
                </div>

                <FormField label="Notes" error={errors.notes} hint="Optional. Not visible to residents.">
                    <textarea
                        value={data.notes}
                        onChange={(e) => setData('notes', e.target.value)}
                        rows={3}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    />
                </FormField>

                <div className="mt-2 flex flex-col-reverse gap-3 sm:flex-row">
                    <Link
                        href={editing ? `/admin/properties/${property.id}` : '/admin/properties'}
                        className="inline-flex min-h-touch items-center justify-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="min-h-touch rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : editing ? 'Save changes' : 'Add property'}
                    </button>
                </div>
            </form>
        </AdminLayout>
    );
}
