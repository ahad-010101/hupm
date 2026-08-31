import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import Alert from '@/Components/Alert';
import ConfirmDialog from '@/Components/ConfirmDialog';

/**
 * Why a unit cannot be removed, in words.
 *
 * The action stays visible and says what is in the way, because an absent
 * button and an unbuilt feature look identical from the outside.
 */
function unitBlockedReason(unit) {
    const leases = `${unit.lease_count} ${unit.lease_count === 1 ? 'lease' : 'leases'}`;
    const ledger =
        unit.ledger_count > 0
            ? ` with ${unit.ledger_count} ledger ${unit.ledger_count === 1 ? 'entry' : 'entries'}`
            : '';

    return `Has ${leases}${ledger}. Financial history is kept permanently.`;
}

/**
 * Why a property cannot be removed.
 *
 * Three phrasings rather than one template, because "1 of the 1 units has lease
 * history" is what a template produces and not what anybody says.
 */
function propertyBlockedReason({ blocking_units: blocking, unit_count: total }) {
    if (total === 1) {
        return 'The only unit at this property has lease history, so the property cannot be removed.';
    }

    if (blocking === total) {
        return `All ${total} units have lease history, so this property cannot be removed.`;
    }

    return `${blocking} of the ${total} units ${
        blocking === 1 ? 'has' : 'have'
    } lease history, so this property cannot be removed.`;
}

/** Property detail with its units.  [FR-REG-01, UI §3.10] */
export default function Show({
    property,
    units,
    deletion = { allowed: true, unit_count: 0, blocking_units: 0 },
    flash = {},
    errors = {},
}) {
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
                <span className="flex flex-col items-end gap-1">
                    <span className="flex justify-end gap-3">
                        <Link
                            href={`/admin/properties/${property.id}/units/${u.id}/edit`}
                            className="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            Edit
                        </Link>
                        {/* A unit with lease history still shows the action —
                            disabled, and with the reason beside it. An absent
                            button cannot be told apart from an unbuilt one. */}
                        <button
                            type="button"
                            disabled={!u.is_deletable}
                            // The reason travels in the label rather than via
                            // aria-describedby: DataTable renders every row
                            // twice (a table on desktop, cards on mobile), so a
                            // per-unit id would be duplicated and resolve to the
                            // wrong copy.
                            aria-label={
                                u.is_deletable
                                    ? undefined
                                    : `Remove unit ${u.unit_number} — unavailable. ${unitBlockedReason(u)}`
                            }
                            onClick={() =>
                                setConfirming({ type: 'unit', id: u.id, label: `unit ${u.unit_number}` })
                            }
                            className={
                                u.is_deletable
                                    ? 'underline text-overdue-fg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg'
                                    : 'text-gray-500 cursor-not-allowed'
                            }
                        >
                            Remove
                        </button>
                    </span>
                    {!u.is_deletable && (
                        <span className="text-sm text-gray-600">{unitBlockedReason(u)}</span>
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
                    {/* Always present. Lease-free units go with the property in
                        one act; a unit carrying history blocks it, and the
                        sentence below says which and how many. */}
                    <button
                        type="button"
                        disabled={!deletion.allowed}
                        aria-describedby={deletion.allowed ? undefined : 'property-blocked'}
                        onClick={() => setConfirming({ type: 'property', label: property.name })}
                        className={
                            deletion.allowed
                                ? 'inline-flex min-h-touch items-center rounded-md border border-overdue-border px-4 text-base font-medium text-overdue-fg hover:bg-overdue-bg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg'
                                : 'inline-flex min-h-touch cursor-not-allowed items-center rounded-md border border-gray-300 px-4 text-base font-medium text-gray-500'
                        }
                    >
                        Remove property
                    </button>
                </div>

                {!deletion.allowed && (
                    <p id="property-blocked" className="mt-2 text-sm text-gray-600">
                        {propertyBlockedReason(deletion)} Financial history is kept permanently.
                    </p>
                )}
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
                {confirming?.type === 'property' && units.length > 0
                    ? `This removes ${property.name} and its ${units.length} ${
                          units.length === 1 ? 'unit' : 'units'
                      }, none of which has any lease history. This cannot be undone.`
                    : 'This cannot be undone. Records with any lease or financial history are kept regardless.'}
            </ConfirmDialog>
        </AdminLayout>
    );
}
