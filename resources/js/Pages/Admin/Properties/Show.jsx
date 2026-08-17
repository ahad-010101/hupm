import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import Alert from '@/Components/Alert';
import ConfirmDialog from '@/Components/ConfirmDialog';

/** Property detail with its units.  [FR-REG-01, UI §3.10] */
export default function Show({ property, units, flash = {}, errors = {} }) {
    const [confirming, setConfirming] = useState(null); // { type, id, label }
    const [processing, setProcessing] = useState(false);

    const confirmDelete = () => {
        setProcessing(true);
        const url =
            confirming.type === 'property'
                ? `/admin/properties/${property.id}`
                : `/admin/properties/${property.id}/units/${confirming.id}`;

        router.delete(url, {
            onFinish: () => {
                setProcessing(false);
                setConfirming(null);
            },
        });
    };

    const columns = [
        { key: 'unit_number', header: 'Unit' },
        { key: 'bedrooms', header: 'Beds', align: 'right', render: (u) => u.bedrooms ?? '—' },
        { key: 'bathrooms', header: 'Baths', align: 'right', render: (u) => u.bathrooms ?? '—' },
        { key: 'status', header: 'Status', render: (u) => <StatusBadge status={u.status} /> },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (u) => (
                <span className="flex justify-end gap-3">
                    <Link
                        href={`/admin/properties/${property.id}/units/${u.id}/edit`}
                        className="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Edit
                    </Link>
                    {/* A unit with lease history cannot be removed, so the
                        action is absent rather than present-and-failing. */}
                    {u.is_deletable && (
                        <button
                            type="button"
                            onClick={() => setConfirming({ type: 'unit', id: u.id, label: `unit ${u.unit_number}` })}
                            className="underline text-overdue-fg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg"
                        >
                            Remove
                        </button>
                    )}
                </span>
            ),
        },
    ];

    return (
        <AdminLayout header={property.name}>
            <Head title={property.name} />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {(errors.property || errors.unit) && (
                <Alert tone="error" className="mb-4" title="That could not be removed">
                    {errors.property || errors.unit}
                </Alert>
            )}

            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-4">
                <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                    <div className="flex justify-between gap-4 border-b border-gray-100 py-1">
                        <dt className="text-sm text-gray-600">Address</dt>
                        <dd className="text-base text-right">
                            {[
                                property.street_address,
                                property.address_line_2,
                                property.city,
                                `${property.state ?? ''} ${property.postal_code ?? ''}`.trim(),
                                // The country is shown only when it is not the
                                // US — printing it on every Atlanta address is
                                // noise, and its absence is the signal.
                                property.country_code === 'US' ? null : property.country_code,
                            ]
                                .filter(Boolean)
                                .join(', ')}
                        </dd>
                    </div>
                    <div className="flex justify-between gap-4 border-b border-gray-100 py-1">
                        <dt className="text-sm text-gray-600">County</dt>
                        <dd className="text-base">{property.county ?? '—'}</dd>
                    </div>
                </dl>

                {property.notes && <p className="mt-3 text-base text-gray-700">{property.notes}</p>}

                <div className="mt-4 flex flex-wrap gap-3">
                    <Link
                        href={`/admin/properties/${property.id}/edit`}
                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Edit property
                    </Link>
                    {units.length === 0 && (
                        <button
                            type="button"
                            onClick={() => setConfirming({ type: 'property', label: property.name })}
                            className="inline-flex min-h-touch items-center rounded-md border border-overdue-border px-4 text-base font-medium text-overdue-fg hover:bg-overdue-bg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg"
                        >
                            Remove property
                        </button>
                    )}
                </div>
            </div>

            <div className="mb-3 flex items-center justify-between">
                <h2 className="text-lg font-semibold text-gray-900">Units</h2>
                <Link
                    href={`/admin/properties/${property.id}/units/create`}
                    className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    Add unit
                </Link>
            </div>

            <DataTable
                columns={columns}
                rows={units}
                caption={`Units at ${property.name}`}
                empty={
                    <EmptyState
                        title="No units yet."
                        description="Add a unit before creating a lease for this property."
                    />
                }
            />

            <ConfirmDialog
                open={confirming !== null}
                title={`Remove ${confirming?.label}?`}
                confirmLabel="Remove"
                tone="danger"
                processing={processing}
                onCancel={() => setConfirming(null)}
                onConfirm={confirmDelete}
            >
                This cannot be undone. Records with any lease or financial history are kept
                regardless.
            </ConfirmDialog>
        </AdminLayout>
    );
}
