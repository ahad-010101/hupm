import { useMemo } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';
import FormField from '@/Components/FormField';

/**
 * Raising a repair on somebody's behalf.  [WP-46, FR-MNT-01]
 *
 * The resident's own form asks a person about their own home. This asks the
 * office about somebody else's, so it adds the three things an admin usually
 * already knows while the telephone is still in their hand: who the contractor
 * is, whether the resident is being charged, and whether they should see it at
 * all.
 */
export default function Create({ categories = {}, leases = [], vendors = [], errors = {} }) {
    const form = useForm({
        lease_id: '',
        category: '',
        description: '',
        date_began: '',
        is_emergency: false,
        internal: false,
        permission_to_enter: false,
        preferred_contact: 'phone',
        contact_phone: '',
        pets_present: false,
        best_access_time: '',
        vendor_id: '',
        bill_resident: false,
        billed_amount: '',
        billed_reason: '',
    });

    const chosen = useMemo(
        () => leases.find((l) => String(l.id) === String(form.data.lease_id)),
        [leases, form.data.lease_id],
    );

    const pickLease = (id) => {
        const lease = leases.find((l) => String(l.id) === String(id));

        form.setData((data) => ({
            ...data,
            lease_id: id,
            // Their number, so nobody is looking it up on another screen while
            // somebody waits on the telephone.
            contact_phone: lease?.phone ?? '',
        }));
    };

    const submit = (e) => {
        e.preventDefault();
        form.post('/admin/maintenance');
    };

    return (
        <AdminLayout header="Raise a ticket">
            <Head title="Raise a ticket" />

            {errors.lease_id && (
                <Alert tone="error" className="mb-4" title="That could not be raised">
                    {errors.lease_id}
                </Alert>
            )}

            <form onSubmit={submit} className="max-w-3xl rounded-lg border border-gray-200 bg-white p-6">
                <FormField label="Resident" error={form.errors.lease_id} required>
                    <select
                        value={form.data.lease_id}
                        onChange={(e) => pickLease(e.target.value)}
                        required
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        <option value="">Choose…</option>
                        {leases.map((lease) => (
                            <option key={lease.id} value={lease.id}>
                                {lease.tenant} — {lease.property}, unit {lease.unit}
                            </option>
                        ))}
                    </select>
                </FormField>

                <FormField label="What sort of problem" error={form.errors.category} required>
                    <select
                        value={form.data.category}
                        onChange={(e) => form.setData('category', e.target.value)}
                        required
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        <option value="">Choose…</option>
                        {Object.entries(categories).map(([value, label]) => (
                            <option key={value} value={value}>{label}</option>
                        ))}
                    </select>
                </FormField>

                <FormField label="What is wrong" error={form.errors.description} required>
                    <textarea
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        rows={4}
                        maxLength={5000}
                        required
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    />
                </FormField>

                <div className="grid gap-x-4 sm:grid-cols-2">
                    <FormField
                        label="Since when"
                        value={form.data.date_began}
                        onChange={(e) => form.setData('date_began', e.target.value)}
                        error={form.errors.date_began}
                        type="date"
                        hint="Optional."
                    />
                    <FormField
                        label="Contact number"
                        value={form.data.contact_phone}
                        onChange={(e) => form.setData('contact_phone', e.target.value)}
                        error={form.errors.contact_phone}
                        hint={chosen ? 'From their record — change it if they gave another.' : ' '}
                    />
                </div>

                <FormField
                    label="Best time to call round"
                    value={form.data.best_access_time}
                    onChange={(e) => form.setData('best_access_time', e.target.value)}
                    error={form.errors.best_access_time}
                    maxLength={100}
                    hint="Optional. For example, “After 4pm”."
                />

                <fieldset className="mt-4 space-y-2">
                    <legend className="text-base font-medium text-gray-900">Getting in</legend>

                    <label className="flex min-h-touch items-center gap-3 text-base">
                        <input
                            type="checkbox"
                            checked={form.data.permission_to_enter}
                            onChange={(e) => form.setData('permission_to_enter', e.target.checked)}
                            className="rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                        />
                        {/* Unticked by default, deliberately. Assuming a
                            permission nobody gave is the one default here that
                            could put a contractor in a home uninvited. */}
                        They have given permission to enter when out
                    </label>

                    <label className="flex min-h-touch items-center gap-3 text-base">
                        <input
                            type="checkbox"
                            checked={form.data.pets_present}
                            onChange={(e) => form.setData('pets_present', e.target.checked)}
                            className="rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                        />
                        There is a pet at the property
                    </label>

                    <label className="flex min-h-touch items-center gap-3 text-base">
                        <input
                            type="checkbox"
                            checked={form.data.is_emergency}
                            onChange={(e) => form.setData('is_emergency', e.target.checked)}
                            className="rounded border-gray-300 text-overdue-fg focus:ring-overdue-fg"
                        />
                        This is an emergency
                    </label>
                </fieldset>

                <FormField label="Assign a contractor now" error={form.errors.vendor_id}>
                    <select
                        value={form.data.vendor_id}
                        onChange={(e) => form.setData('vendor_id', e.target.value)}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        <option value="">Not yet — leave it in the queue</option>
                        {vendors.map((v) => (
                            <option key={v.id} value={v.id}>{v.label}</option>
                        ))}
                    </select>
                </FormField>

                <fieldset className="mt-4 rounded-md border border-gray-200 p-3">
                    <legend className="px-1 text-base font-medium text-gray-900">Charging the resident</legend>

                    <label className="flex min-h-touch items-center gap-3 text-base">
                        <input
                            type="checkbox"
                            checked={form.data.bill_resident}
                            onChange={(e) => form.setData('bill_resident', e.target.checked)}
                            className="rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                        />
                        {/* Unticked by default: most repairs are the
                            landlord's, and a pre-ticked box is how somebody
                            gets billed for a boiler. */}
                        Bill this repair to the resident
                    </label>

                    {form.data.bill_resident && (
                        <div className="mt-3">
                            <FormField
                                label="Amount"
                                value={form.data.billed_amount}
                                onChange={(e) => form.setData('billed_amount', e.target.value)}
                                error={form.errors.billed_amount}
                                type="number"
                                inputMode="decimal"
                                min="0.01"
                                step="0.01"
                                required
                            />
                            <FormField
                                label="Why"
                                value={form.data.billed_reason}
                                onChange={(e) => form.setData('billed_reason', e.target.value)}
                                error={form.errors.billed_reason}
                                maxLength={500}
                                hint="Appears on their ledger with the ticket number, and cannot be edited afterwards."
                                required
                            />
                        </div>
                    )}
                </fieldset>

                <label className="mt-4 flex min-h-touch items-start gap-3 text-base">
                    <input
                        type="checkbox"
                        checked={form.data.internal}
                        onChange={(e) => form.setData('internal', e.target.checked)}
                        className="mt-1 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                    />
                    <span>
                        Keep this internal
                        <span className="block text-sm text-gray-600">
                            The resident will not see it and will receive no email about it — including
                            when a contractor is assigned. For work on the unit that is not theirs to
                            follow, like a renewal or an end-of-tenancy job.
                        </span>
                    </span>
                </label>

                <div className="mt-6 flex flex-wrap gap-3">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                    >
                        {form.processing ? 'Raising…' : 'Raise the ticket'}
                    </button>
                    <Link
                        href="/admin/maintenance"
                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </AdminLayout>
    );
}
