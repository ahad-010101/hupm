import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';
import Alert from '@/Components/Alert';
import ConfirmDialog from '@/Components/ConfirmDialog';

/** Housing authorities.  [FR-REG-01, GATE Q-2] */
export default function Index({ authorities, flash = {}, errors = {} }) {
    const [confirming, setConfirming] = useState(null);
    const [processing, setProcessing] = useState(false);

    const columns = [
        { key: 'name', header: 'Authority' },
        {
            key: 'contact',
            header: 'Contact',
            render: (a) => a.contact_name || a.contact_email || a.contact_phone || '—',
        },
        {
            key: 'remittance_type',
            header: 'Remits',
            render: (a) => (
                <StatusBadge
                    status={a.remittance_type}
                    label={a.remittance_type === 'lump_sum' ? 'Lump sum' : 'Per tenant'}
                    tone={a.remittance_type === 'lump_sum' ? 'due' : 'neutral'}
                />
            ),
        },
        { key: 'leases_count', header: 'Leases', align: 'right' },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (a) => (
                <span className="flex justify-end gap-3">
                    <Link
                        href={`/admin/housing-authorities/${a.id}/edit`}
                        className="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Edit
                    </Link>
                    {a.is_deletable && (
                        <button
                            type="button"
                            onClick={() => setConfirming(a)}
                            className="underline text-overdue-fg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg"
                        >
                            Remove
                        </button>
                    )}
                </span>
            ),
        },
    ];

    const anyLumpSum = authorities.some((a) => a.remittance_type === 'lump_sum');

    return (
        <AdminLayout header="Housing authorities">
            <Head title="Housing authorities" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {errors.authority && (
                <Alert tone="error" className="mb-4" title="That could not be removed">
                    {errors.authority}
                </Alert>
            )}

            {anyLumpSum && (
                <Alert tone="warning" className="mb-4" title="Lump-sum remittance is set">
                    Payments from a lump-sum authority arrive as one amount covering several
                    residents, so they need allocating by hand. That screen arrives with the
                    payments work.
                </Alert>
            )}

            <div className="mb-4 flex justify-end">
                <Link
                    href="/admin/housing-authorities/create"
                    className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    Add authority
                </Link>
            </div>

            <DataTable
                columns={columns}
                rows={authorities}
                caption="Housing authorities"
                empty={
                    <EmptyState
                        title="No housing authorities yet."
                        description="Add the agency that administers your Section 8 tenancies."
                    />
                }
            />

            <ConfirmDialog
                open={confirming !== null}
                title={`Remove ${confirming?.name}?`}
                confirmLabel="Remove"
                tone="danger"
                processing={processing}
                onCancel={() => setConfirming(null)}
                onConfirm={() => {
                    setProcessing(true);
                    router.delete(`/admin/housing-authorities/${confirming.id}`, {
                        onFinish: () => {
                            setProcessing(false);
                            setConfirming(null);
                        },
                    });
                }}
            >
                This cannot be undone.
            </ConfirmDialog>
        </AdminLayout>
    );
}
