import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import FormField from '@/Components/FormField';
import Alert from '@/Components/Alert';

/**
 * Property create/edit.  [FR-REG-01, AC-REG-02, D-19, FR-NTF-03]
 *
 * Country → state → city → county, each level disabled until the one above it
 * is chosen, then the address lines and postal code. Levels are fetched as the
 * selection narrows: the full city list is 150,000 rows, so shipping it to the
 * browser is not an option.
 *
 * County is a list, not a text box, wherever we hold one. It is not a
 * formality — the National Weather Service names an alert's area as
 * "Fulton; DeKalb; Cobb" and a property is matched to an alert on that string,
 * so "Dekalb" typed in a hurry saves happily and then never matches an alert
 * again. Where we hold no list for a state the field falls back to free text,
 * because a county we cannot check is still better than no county at all.
 */
export default function Form({
    property,
    countries,
    states: initialStates,
    cities: initialCities,
    counties: initialCounties,
    selectedStateId,
}) {
    const editing = Boolean(property);

    const [states, setStates] = useState(initialStates ?? []);
    const [cities, setCities] = useState(initialCities ?? []);
    const [counties, setCounties] = useState(initialCounties ?? []);
    const [stateId, setStateId] = useState(selectedStateId ?? '');
    const [loading, setLoading] = useState(null); // 'states' | 'cities' | null

    const { data, setData, post, patch, processing, errors } = useForm({
        name: property?.name ?? '',
        country_code: property?.country_code ?? 'US',
        state: property?.state ?? '',
        city: property?.city ?? '',
        street_address: property?.street_address ?? '',
        address_line_2: property?.address_line_2 ?? '',
        postal_code: property?.postal_code ?? '',
        county: property?.county ?? '',
        notes: property?.notes ?? '',
    });

    const load = async (url) => {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        return response.ok ? response.json() : null;
    };

    const onCountryChange = async (code) => {
        // Clearing the levels below is the point of a cascade: leaving
        // "Ontario" selected under the United States is how bad data is stored.
        setData((current) => ({ ...current, country_code: code, state: '', city: '', county: '' }));
        setStates([]);
        setCities([]);
        setCounties([]);
        setStateId('');

        if (!code) return;

        setLoading('states');
        const payload = await load(`/admin/address/states?country=${code}`);
        setStates(payload?.states ?? []);
        setLoading(null);
    };

    const onStateChange = async (id) => {
        const chosen = states.find((s) => String(s.id) === String(id));
        const name = chosen?.name ?? '';

        setStateId(id);
        setData((current) => ({ ...current, state: name, city: '', county: '' }));
        setCities([]);
        setCounties([]);

        if (!id) return;

        setLoading('cities');

        // Both levels at once. They depend on the same choice, and fetching
        // them in sequence would leave the county box briefly claiming the
        // state has no counties.
        const [cityPayload, countyPayload] = await Promise.all([
            load(`/admin/address/cities?state=${id}`),
            load(`/admin/address/counties?state=${encodeURIComponent(name)}`),
        ]);

        setCities(cityPayload?.cities ?? []);
        setCounties(countyPayload?.counties ?? []);
        setLoading(null);
    };

    const submit = (e) => {
        e.preventDefault();
        editing ? patch(`/admin/properties/${property.id}`) : post('/admin/properties');
    };

    const selectClasses =
        'block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600 disabled:bg-gray-100 disabled:text-gray-500';

    // A list for this state, or nothing to choose from.
    const hasCountyList = counties.length > 0;

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

                    <div className="grid gap-x-4 sm:grid-cols-3">
                        <FormField label="Country" error={errors.country_code} required>
                            <select
                                value={data.country_code}
                                onChange={(e) => onCountryChange(e.target.value)}
                                className={selectClasses}
                            >
                                <option value="">Choose a country…</option>
                                {countries.map((c) => (
                                    <option key={c.iso2} value={c.iso2}>{c.name}</option>
                                ))}
                            </select>
                        </FormField>

                        <FormField label="State / Province" error={errors.state} required>
                            <select
                                value={stateId}
                                onChange={(e) => onStateChange(e.target.value)}
                                disabled={!data.country_code || loading === 'states'}
                                className={selectClasses}
                            >
                                <option value="">
                                    {!data.country_code
                                        ? 'Choose a country first'
                                        : loading === 'states'
                                          ? 'Loading…'
                                          : 'Choose a state / province…'}
                                </option>
                                {states.map((s) => (
                                    <option key={s.id} value={s.id}>{s.name}</option>
                                ))}
                            </select>
                        </FormField>

                        <FormField label="City" error={errors.city} required>
                            <select
                                value={data.city}
                                onChange={(e) => setData('city', e.target.value)}
                                disabled={!stateId || loading === 'cities'}
                                className={selectClasses}
                            >
                                <option value="">
                                    {!stateId
                                        ? 'Choose a state first'
                                        : loading === 'cities'
                                          ? 'Loading…'
                                          : 'Choose a city…'}
                                </option>
                                {cities.map((c) => (
                                    <option key={c.id} value={c.name}>{c.name}</option>
                                ))}
                            </select>
                        </FormField>
                    </div>

                    {data.country_code && data.country_code !== 'US' && (
                        // Said at the moment of the choice rather than
                        // discovered when the alerts never arrive.
                        <Alert tone="warning" className="mb-4" title="Weather alerts are US only">
                            The National Weather Service covers the United States, so residents at this
                            property will not receive automated weather alerts.
                        </Alert>
                    )}

                    <FormField
                        label="Address"
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
                            label="Postal code"
                            value={data.postal_code}
                            onChange={(e) => setData('postal_code', e.target.value)}
                            error={errors.postal_code}
                            maxLength={16}
                            autoComplete="postal-code"
                            hint={
                                data.country_code === 'US'
                                    ? 'Five digits. This determines which weather alerts residents receive.'
                                    : undefined
                            }
                            required
                        />

                        {hasCountyList ? (
                            <FormField
                                label="County"
                                error={errors.county}
                                hint="Optional, but weather alerts are matched by county."
                            >
                                <select
                                    value={data.county}
                                    onChange={(e) => setData('county', e.target.value)}
                                    className={selectClasses}
                                >
                                    <option value="">No county recorded</option>
                                    {counties.map((c) => (
                                        <option key={c} value={c}>{c}</option>
                                    ))}
                                </select>
                            </FormField>
                        ) : (
                            <FormField
                                label="County"
                                value={data.county}
                                onChange={(e) => setData('county', e.target.value)}
                                error={errors.county}
                                maxLength={100}
                                disabled={!stateId}
                                hint={
                                    stateId
                                        ? 'Optional. We have no list for this state, so type it as the weather service spells it.'
                                        : 'Choose a state first.'
                                }
                            />
                        )}
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
