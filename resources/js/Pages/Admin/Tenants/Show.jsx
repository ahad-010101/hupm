import { Head, Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import Alert from '@/Components/Alert';
import Money from '@/Components/Money';

/** Tenant detail: contact, portal account, leases.  [FR-REG-02, API-ADM-07] */
export default function Show({ tenant, account, leases, flash = {}, errors = {} }) {
    const invite = useForm({});

    const leaseColumns = [
        {
            key: 'property',
            header: 'Property',
            render: (l) => (
                <Link
                    href={`/admin/leases/${l.id}/edit`}
                    className="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    {l.property} — unit {l.unit}
                </Link>
            ),
        },
        { key: 'term', header: 'Term', render: (l) => `${l.start_date} to ${l.end_date}` },
        {
            key: 'tenant_portion',
            header: 'Tenant portion',
            align: 'right',
            render: (l) => <Money value={l.tenant_portion} />,
        },
        {
            // The admin console legitimately shows this (§5.3). It must never
            // appear in the tenant portal (I-4).
            key: 'ha_portion',
            header: 'HA portion',
            align: 'right',
            hideOnMobile: true,
            render: (l) => <Money value={l.ha_portion} />,
        },
        { key: 'status', header: 'Status', align: 'right', render: (l) => <StatusBadge status={l.status} /> },
    ];

    return (
        <AdminLayout header={tenant.name}>
            <Head title={tenant.name} />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {(errors.invite || errors.tenant) && (
                <Alert tone="error" className="mb-4" title="That did not work">
                    {errors.invite || errors.tenant}
                </Alert>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                <section className="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 className="mb-3 text-lg font-semibold text-gray-900">Contact</h2>
                    <dl className="space-y-2">
                        <div className="flex justify-between gap-4 border-b border-gray-100 pb-1">
                            <dt className="text-sm text-gray-600">Email</dt>
                            <dd className="text-base">
                                {tenant.contactable ? tenant.email : <StatusBadge status="not_deliverable" />}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-4 border-b border-gray-100 pb-1">
                            <dt className="text-sm text-gray-600">Phone</dt>
                            <dd className="text-base">{tenant.phone ?? '—'}</dd>
                        </div>
                        <div className="flex justify-between gap-4 border-b border-gray-100 pb-1">
                            <dt className="text-sm text-gray-600">Emergency contact</dt>
                            <dd className="text-base text-right">
                                {tenant.emergency_contact_name ?? '—'}
                                {tenant.emergency_contact_phone && (
                                    <span className="block text-sm text-gray-600">
                                        {tenant.emergency_contact_phone}
                                    </span>
                                )}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-sm text-gray-600">Status</dt>
                            <dd><StatusBadge status={tenant.status} /></dd>
                        </div>
                    </dl>

                    {tenant.notes && (
                        <p className="mt-3 border-t border-gray-100 pt-3 text-base text-gray-700">{tenant.notes}</p>
                    )}

                    <Link
                        href={`/admin/tenants/${tenant.id}/edit`}
                        className="mt-4 inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Edit details
                    </Link>
                </section>

                <section className="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 className="mb-3 text-lg font-semibold text-gray-900">Portal account</h2>

                    {!tenant.contactable ? (
                        // Q-4: a legitimate state, not a fault. Say what it means
                        // and what to do instead.
                        <Alert tone="warning" title="No email address on file">
                            This tenant cannot have an online account and will not receive any email.
                            Add an address to invite them, or continue serving them by phone.
                        </Alert>
                    ) : account ? (
                        <>
                            <dl className="space-y-2">
                                <div className="flex justify-between gap-4 border-b border-gray-100 pb-1">
                                    <dt className="text-sm text-gray-600">Account</dt>
                                    <dd><StatusBadge status={account.status} /></dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-sm text-gray-600">Last signed in</dt>
                                    <dd className="text-base">{account.last_login_at ?? 'Never'}</dd>
                                </div>
                            </dl>

                            {account.status === 'invited' && (
                                <button
                                    type="button"
                                    disabled={invite.processing}
                                    onClick={() => invite.post(`/admin/tenants/${tenant.id}/invite`)}
                                    className="mt-4 min-h-touch rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                >
                                    {invite.processing ? 'Sending…' : 'Resend set-up link'}
                                </button>
                            )}
                        </>
                    ) : (
                        <>
                            <p className="text-base text-gray-700">
                                No account yet. Inviting them emails a single-use link to choose their own
                                password.
                            </p>
                            <button
                                type="button"
                                disabled={invite.processing}
                                onClick={() => invite.post(`/admin/tenants/${tenant.id}/invite`)}
                                className="mt-4 min-h-touch rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60"
                            >
                                {invite.processing ? 'Sending…' : 'Invite to the portal'}
                            </button>
                        </>
                    )}
                </section>
            </div>

            <div className="mb-3 mt-6 flex items-center justify-between">
                <h2 className="text-lg font-semibold text-gray-900">Leases</h2>
                <Link
                    href={`/admin/leases/create?tenant_id=${tenant.id}`}
                    className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    New lease
                </Link>
            </div>

            <DataTable
                columns={leaseColumns}
                rows={leases}
                caption={`Leases for ${tenant.name}`}
                empty={<EmptyState title="No leases yet." description="Create a lease to start posting rent." />}
            />
        </AdminLayout>
    );
}
