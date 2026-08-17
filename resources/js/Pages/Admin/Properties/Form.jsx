import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import FormField from '@/Components/FormField';
import Alert from '@/Components/Alert';

/**
 * Property create/edit.  [FR-REG-01, AC-REG-02, D-19]
 *
 * The address section follows the selected country rather than assuming the
 * United States: the third line is called "State" in the US, "Province" in
 * Canada and "Prefecture" in Japan, and it is a dropdown where the country has
 * a fixed list and a text box where it does not (the UK, for instance).
 *
 * Subdivisions for the initial country arrive with the page. Changing the
 * country fetches the new list — the only client-side fetch in the application,
 * because shipping all 256 countries' subdivisions with every form would be
 * megabytes to save one small request.
 */
export default function Form({ property, countries, subdivisions: initialSubdivisions, addressFormat }) {
    const editing = Boolean(property);

    const [subdivisions, setSubdivisions] = useState(initialSubdivisions ?? []);
    const [format, setFormat] = useState(addressFormat);
    const [loadingCountry, setLoadingCountry] = useState(false);

    const { data, setData, post, patch, processing, errors } = useForm({
        name: property?.name ?? '',
        country_code: property?.country_code ?? 'US',
        street_address: property?.street_address ?? '',
        address_line_2: property?.address_line_2 ?? '',
        city: property?.city ?? '',
        state: property?.state ?? 'GA',
        postal_code: property?.postal_code ?? '',
        county: property?.county ?? '',
        notes: property?.notes ?? '',
    });

    const changeCountry = async (code) => {
        setData((current) => ({ ...current, country_code: code, state: '' }));
        setLoadingCountry(true);

        try {
            const response = await fetch(`/admin/address/subdivisions?country=${code}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (response.ok) {
                const payload = await response.json();
                setSubdivisions(payload.subdivisions);
                setFormat(payload.format);
            }
        } finally {
            setLoadingCountry(false);
        }
    };

    const submit = (e) => {
        e.preventDefault();
        editing ? patch(`/admin/properties/${property.id}`) : post('/admin/properties');
    };

    return (
        <AdminLayout header={editing ? `Edit ${property.name}` : 'Add property'}>
            <Head title={editing ? 'Edit property' : 'Add property'} />

            <form onSubmit={submit} noValidate className="max-w-3xl">
                <FormField
                    label="Property name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    error={errors.name}
                    maxLength={150}
                    required
                />

                <fieldset className="mb-4">
                    <legend className="mb-2 text-lg font-semibold text-gray-900">Address</legend>

                    <FormField label="Country" error={errors.country_code} required>
                        <select
                            value={data.country_code}
                            onChange={(e) => changeCountry(e.target.value)}
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        >
                            {countries.map((c) => (
                                <option key={c.code} value={c.code}>{c.name}</option>
                            ))}
                        </select>
                    </FormField>

                    {data.country_code !== 'US' && (
                        // The consequence has to be visible at the moment of the
                        // choice, not discovered when the alerts never arrive.
                        <Alert tone="warning" className="mb-4" title="Weather alerts are US only">
                            The National Weather Service covers the United States, so residents at this
                            property will not receive automated weather alerts.
                        </Alert>
                    )}

                    <FormField
                        label="Address line 1"
                        value={data.street_address}
                        onChange={(e) => setData('street_address', e.target.value)}
                        error={errors.street_address}
                        maxLength={255}
                        autoComplete="address-line1"
                        required
                    />

                    <FormField
                        label="Address line 2"
                        value={data.address_line_2}
                        onChange={(e) => setData('address_line_2', e.target.value)}
                        error={errors.address_line_2}
                        maxLength={255}
                        autoComplete="address-line2"
                        hint="Optional. Building, floor or suite."
                    />

                    <div className="grid gap-x-4 sm:grid-cols-2">
                        <FormField
                            label={format?.locality_label ?? 'City'}
                            value={data.city}
                            onChange={(e) => setData('city', e.target.value)}
                            error={errors.city}
                            maxLength={100}
                            autoComplete="address-level2"
                            required
                        />

                        <FormField label="County" error={errors.county} maxLength={100} hint="Optional.">
                            <input
                                type="text"
                                value={data.county}
                                onChange={(e) => setData('county', e.target.value)}
                                maxLength={100}
                                className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                            />
                        </FormField>

                        {/* A select where the country has a fixed list, a text
                            box where it does not — an empty dropdown is worse
                            than no dropdown. */}
                        <FormField
                            label={format?.administrative_area_label ?? 'State or province'}
                            error={errors.state}
                            required={Boolean(format?.has_subdivisions)}
                        >
                            {format?.has_subdivisions ? (
                                <select
                                    value={data.state}
                                    onChange={(e) => setData('state', e.target.value)}
                                    disabled={loadingCountry}
                                    autoComplete="address-level1"
                                    className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600 disabled:bg-gray-100"
                                >
                                    <option value="">
                                        {loadingCountry ? 'Loading…' : `Choose a ${(format?.administrative_area_label ?? 'region').toLowerCase()}…`}
                                    </option>
                                    {subdivisions.map((s) => (
                                        <option key={s.code} value={s.code}>{s.name}</option>
                                    ))}
                                </select>
                            ) : (
                                <input
                                    type="text"
                                    value={data.state}
                                    onChange={(e) => setData('state', e.target.value)}
                                    maxLength={64}
                                    autoComplete="address-level1"
                                    className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                                />
                            )}
                        </FormField>

                        <FormField
                            label={`${format?.postal_code_label ?? 'Postal'} code`}
                            value={data.postal_code}
                            onChange={(e) => setData('postal_code', e.target.value)}
                            error={errors.postal_code}
                            maxLength={16}
                            autoComplete="postal-code"
                            required={Boolean(format?.postal_code_required)}
                            hint={
                                data.country_code === 'US'
                                    ? 'Five digits. This determines which weather alerts residents receive.'
                                    : undefined
                            }
                        />
                    </div>
                </fieldset>

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
