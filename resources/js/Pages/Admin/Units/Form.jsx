import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import FormField from '@/Components/FormField';

/** Unit create/edit, nested under its property.  [FR-REG-01, AC-REG-01] */
export default function Form({ property, unit }) {
    const editing = Boolean(unit);

    const { data, setData, post, patch, processing, errors } = useForm({
        unit_number: unit?.unit_number ?? '',
        bedrooms: unit?.bedrooms ?? '',
        bathrooms: unit?.bathrooms ?? '',
        status: unit?.status ?? 'vacant',
    });

    const submit = (e) => {
        e.preventDefault();
        editing
            ? patch(`/admin/properties/${property.id}/units/${unit.id}`)
            : post(`/admin/properties/${property.id}/units`);
    };

    return (
        <AdminLayout header={editing ? `Edit unit ${unit.unit_number}` : 'Add unit'}>
            <Head title={editing ? 'Edit unit' : 'Add unit'} />

            <p className="mb-4 text-base text-gray-700">
                At <span className="font-medium">{property.name}</span>
            </p>

            <form onSubmit={submit} noValidate className="max-w-xl">
                <FormField
                    label="Unit number"
                    value={data.unit_number}
                    onChange={(e) => setData('unit_number', e.target.value)}
                    error={errors.unit_number}
                    maxLength={20}
                    hint="Must be unique within this property."
                    required
                />

                <div className="grid gap-x-4 sm:grid-cols-2">
                    <FormField
                        label="Bedrooms"
                        type="number"
                        value={data.bedrooms}
                        onChange={(e) => setData('bedrooms', e.target.value)}
                        error={errors.bedrooms}
                        min={0}
                        max={20}
                    />

                    <FormField
                        label="Bathrooms"
                        type="number"
                        value={data.bathrooms}
                        onChange={(e) => setData('bathrooms', e.target.value)}
                        error={errors.bathrooms}
                        step="0.5"
                        min={0}
                        hint="Half-baths allowed, for example 1.5."
                    />
                </div>

                <FormField label="Status" error={errors.status} required>
                    <select
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        <option value="vacant">Vacant</option>
                        <option value="occupied">Occupied</option>
                        <option value="off_market">Off market</option>
                    </select>
                </FormField>

                <div className="mt-2 flex flex-col-reverse gap-3 sm:flex-row">
                    <Link
                        href={`/admin/properties/${property.id}`}
                        className="inline-flex min-h-touch items-center justify-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Cancel
                    </Link>
                    <button
                        type="submit"
                        disabled={processing}
                        className="min-h-touch rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {processing ? 'Saving…' : editing ? 'Save changes' : 'Add unit'}
                    </button>
                </div>
            </form>
        </AdminLayout>
    );
}
