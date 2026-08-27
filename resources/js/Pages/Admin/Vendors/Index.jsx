import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import FormField from '@/Components/FormField';
import Alert from '@/Components/Alert';
import StatusBadge from '@/Components/StatusBadge';
import ConfirmDialog from '@/Components/ConfirmDialog';

/**
 * Contractors.  [API-ADM-38, FR-MNT-03, NG-6]
 *
 * The list the maintenance screen assigns from. One screen with an inline form:
 * a contractor is six fields, and a separate page for six fields is a page
 * nobody wants to visit twice.
 *
 * A vendor is a record, not an account. There is no vendor portal in v1 (NG-6),
 * so the email address is somewhere to send a job and nothing here is a
 * credential — which is why the form asks for no password and offers no invite.
 */

const BLANK = { name: '', trade: '', phone: '', email: '', notes: '', active: true };

function VendorForm({ vendor, onDone }) {
    const editing = Boolean(vendor);

    const form = useForm(vendor ? { ...BLANK, ...vendor } : BLANK);

    const submit = (e) => {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                if (!editing) form.reset();
                onDone?.();
            },
        };

        editing ? form.patch(`/admin/vendors/${vendor.id}`, options) : form.post('/admin/vendors', options);
    };

    return (
        <form onSubmit={submit} className="max-w-2xl">
            <div className="grid gap-x-4 sm:grid-cols-2">
                <FormField
                    label="Name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    error={form.errors.name}
                    maxLength={150}
                    required
                />

                <FormField
                    label="Trade"
                    value={form.data.trade ?? ''}
                    onChange={(e) => form.setData('trade', e.target.value)}
                    error={form.errors.trade}
                    hint="Optional. Plumber, electrician, locksmith."
                    maxLength={100}
                />

                <FormField
                    label="Telephone"
                    type="tel"
                    value={form.data.phone ?? ''}
                    onChange={(e) => form.setData('phone', e.target.value)}
                    error={form.errors.phone}
                    hint="How you actually reach them."
                    maxLength={30}
                />

                <FormField
                    label="Email"
                    type="email"
                    value={form.data.email ?? ''}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                    hint="Optional. For sending work, not for signing in."
                    maxLength={255}
                />
            </div>

            <FormField
                label="Notes"
                error={form.errors.notes}
                hint="Optional. Rates, hours, which properties they know. Residents never see this."
            >
                <textarea
                    value={form.data.notes ?? ''}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    rows={3}
                    maxLength={2000}
                    className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                />
            </FormField>

            <label className="mb-4 flex min-h-touch items-center gap-3">
                <input
                    type="checkbox"
                    checked={form.data.active}
                    onChange={(e) => form.setData('active', e.target.checked)}
                    className="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                />
                <span className="text-base">
                    Offer them on the maintenance screen
                </span>
            </label>

            <div className="flex flex-wrap gap-3">
                <button
                    type="submit"
                    disabled={form.processing}
                    className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                >
                    {form.processing ? 'Saving…' : editing ? 'Save changes' : 'Add contractor'}
                </button>

                {editing && (
                    <button
                        type="button"
                        onClick={onDone}
                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Cancel
                    </button>
                )}
            </div>
        </form>
    );
}

export default function Index({ vendors = [], filters = {}, inactiveCount = 0, flash = {}, errors = {} }) {
    const [editing, setEditing] = useState(null);
    const [removing, setRemoving] = useState(null);

    const toggleInactive = () =>
        router.get('/admin/vendors', filters.inactive ? {} : { inactive: 1 }, { preserveScroll: true });

    const columns = [
        {
            key: 'name',
            header: 'Contractor',
            render: (vendor) => (
                <span>
                    <span className="flex flex-wrap items-center gap-2 font-medium text-gray-900">
                        {vendor.name}
                        {!vendor.active && <StatusBadge status="inactive" label="Not offered" tone="neutral" />}
                    </span>
                    {vendor.trade && <span className="block text-sm text-gray-600">{vendor.trade}</span>}
                </span>
            ),
        },
        {
            key: 'phone',
            header: 'Reach them',
            render: (vendor) => (
                <span>
                    {vendor.phone ? (
                        <a
                            href={`tel:${vendor.phone.replace(/[^0-9+]/g, '')}`}
                            className="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            {vendor.phone}
                        </a>
                    ) : (
                        <span className="text-gray-500">No number</span>
                    )}
                    {vendor.email && <span className="block text-sm text-gray-600">{vendor.email}</span>}
                </span>
            ),
        },
        {
            key: 'open_requests',
            header: 'Work',
            render: (vendor) => (
                <span className="text-gray-700">
                    {vendor.open_requests > 0 ? (
                        <span className="font-medium text-gray-900">{vendor.open_requests} open</span>
                    ) : (
                        <span className="text-gray-500">None open</span>
                    )}
                    <span className="block text-sm text-gray-600">
                        {vendor.total_requests} all time
                    </span>
                </span>
            ),
        },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (vendor) => (
                <span className="flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        onClick={() => setEditing(editing?.id === vendor.id ? null : vendor)}
                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-3 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        {editing?.id === vendor.id ? 'Close' : 'Edit'}
                    </button>
                    <button
                        type="button"
                        onClick={() => setRemoving(vendor)}
                        className="inline-flex min-h-touch items-center rounded-md border border-overdue-border px-3 text-base font-medium text-overdue-fg hover:bg-overdue-bg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg"
                    >
                        Remove
                    </button>
                </span>
            ),
        },
    ];

    return (
        <AdminLayout header="Contractors">
            <Head title="Contractors" />

            <p className="mb-4 max-w-3xl text-base text-gray-700">
                The people you call out. Anyone marked as offered here appears in the assign list on a
                maintenance request. Nobody here has a login — this is a phone book, not an account.
            </p>

            {flash.status && (
                <Alert tone="success" title="Saved" className="mb-4">
                    {flash.status}
                </Alert>
            )}

            {errors.vendor && (
                <Alert tone="error" title="Not removed" className="mb-4">
                    {errors.vendor}
                </Alert>
            )}

            {editing && (
                <section className="mb-6 rounded-lg border border-gray-200 bg-white p-4">
                    <h2 className="mb-4 text-lg font-semibold">Edit {editing.name}</h2>
                    <VendorForm vendor={editing} onDone={() => setEditing(null)} />
                </section>
            )}

            <DataTable
                caption="Contractors"
                columns={columns}
                rows={vendors}
                empty={
                    <EmptyState
                        title="No contractors on file yet."
                        description="Add one below. Until then the assign list on a maintenance request has nothing in it."
                    />
                }
            />

            {inactiveCount > 0 && (
                <p className="mt-4">
                    <button
                        type="button"
                        onClick={toggleInactive}
                        className="inline-flex min-h-touch items-center text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        {filters.inactive
                            ? 'Hide the ones you no longer call'
                            : `Show ${inactiveCount} you no longer call`}
                    </button>
                </p>
            )}

            {!editing && (
                <section className="mt-8 rounded-lg border border-gray-200 bg-white p-4">
                    <h2 className="mb-1 text-lg font-semibold">Add a contractor</h2>
                    <p className="mb-4 text-base text-gray-700">
                        A name is enough to start. Everything else can follow.
                    </p>
                    <VendorForm />
                </section>
            )}

            <ConfirmDialog
                open={Boolean(removing)}
                title={removing ? `Remove ${removing.name}?` : ''}
                confirmLabel="Remove"
                onCancel={() => setRemoving(null)}
                onConfirm={() => {
                    const target = removing;
                    setRemoving(null);
                    router.delete(`/admin/vendors/${target.id}`, { preserveScroll: true });
                }}
            >
                They stay on any past request that names them, so your maintenance history does not
                develop gaps. If you simply want them off the assign list, untick “Offer them on the
                maintenance screen” instead.
            </ConfirmDialog>
        </AdminLayout>
    );
}
