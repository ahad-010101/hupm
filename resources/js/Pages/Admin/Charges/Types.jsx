import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';
import ConfirmDialog from '@/Components/ConfirmDialog';
import DataTable from '@/Components/DataTable';
import EmptyState from '@/Components/EmptyState';
import FormField from '@/Components/FormField';
import Money from '@/Components/Money';
import StatusBadge from '@/Components/StatusBadge';

/**
 * Charge types — the reusable purposes.  [WP-41, FR-CHG-01]
 *
 * Defining one here is what stops the wording and the amount being retyped on
 * every posting. It is a **template, not a live link**: editing a type changes
 * what the next schedule is created with and leaves the residents already on
 * it alone, which the page says out loud because it is the thing an admin will
 * otherwise assume the other way round.
 */
export default function Types({ types = [], categories = {}, flash = {}, errors = {} }) {
    const [editing, setEditing] = useState(null); // null | 'new' | id
    const [confirming, setConfirming] = useState(null);

    const blank = {
        name: '',
        category: 'other',
        default_description: '',
        default_amount: '',
        payer: 'tenant',
        active: true,
    };

    const form = useForm(blank);

    const open = (type) => {
        if (type) {
            form.setData({
                name: type.name,
                category: type.category,
                default_description: type.default_description,
                default_amount: type.default_amount,
                payer: type.payer,
                active: type.active,
            });
            setEditing(type.id);
        } else {
            form.setData(blank);
            setEditing('new');
        }
    };

    const save = () => {
        const done = { onSuccess: () => { setEditing(null); form.reset(); } };

        editing === 'new'
            ? form.post('/admin/charges/types', done)
            : form.patch(`/admin/charges/types/${editing}`, done);
    };

    const columns = [
        {
            key: 'name',
            header: 'Purpose',
            render: (t) => (
                <span>
                    <span className="font-medium text-gray-900">{t.name}</span>
                    <span className="block text-sm text-gray-600">{t.default_description}</span>
                </span>
            ),
        },
        { key: 'category', header: 'Kind', render: (t) => categories[t.category] ?? t.category },
        {
            key: 'default_amount',
            header: 'Default',
            align: 'right',
            render: (t) => <Money value={t.default_amount} />,
        },
        {
            key: 'active_schedules_count',
            header: 'Charged monthly to',
            align: 'right',
            render: (t) =>
                t.active_schedules_count > 0
                    ? `${t.active_schedules_count} ${t.active_schedules_count === 1 ? 'resident' : 'residents'}`
                    : '—',
        },
        {
            key: 'active',
            header: 'Status',
            render: (t) =>
                t.active ? (
                    <StatusBadge status="active" label="In use" tone="settled" />
                ) : (
                    <StatusBadge status="off" label="Switched off" tone="neutral" />
                ),
        },
        {
            key: 'actions',
            header: 'Actions',
            align: 'right',
            render: (t) => (
                <span className="flex flex-col items-end gap-1">
                    <span className="flex justify-end gap-3">
                        <button
                            type="button"
                            onClick={() => open(t)}
                            className="underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                        >
                            Edit
                        </button>
                        {/* Always present, disabled with the reason — an absent
                            button cannot be told apart from an unbuilt one. */}
                        <button
                            type="button"
                            disabled={!t.is_deletable}
                            aria-label={
                                t.is_deletable
                                    ? undefined
                                    : `Remove ${t.name} — unavailable. This type has been used to charge residents.`
                            }
                            onClick={() => setConfirming(t)}
                            className={
                                t.is_deletable
                                    ? 'underline text-overdue-fg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-overdue-fg'
                                    : 'cursor-not-allowed text-gray-500'
                            }
                        >
                            Remove
                        </button>
                    </span>
                    {!t.is_deletable && (
                        <span className="text-sm text-gray-600">
                            Has been used to charge residents. Switch it off instead.
                        </span>
                    )}
                </span>
            ),
        },
    ];

    return (
        <AdminLayout header="Charge types">
            <Head title="Charge types" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {errors.charge_type && (
                <Alert tone="error" className="mb-4" title="That could not be removed">
                    {errors.charge_type}
                </Alert>
            )}

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p className="max-w-2xl text-base text-gray-700">
                    Define a purpose once — garbage collection, pest control — and it fills in the
                    wording and the amount every time you charge it.
                </p>
                <div className="flex gap-3">
                    <Link
                        href="/admin/charges"
                        className="inline-flex min-h-touch items-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Post a charge
                    </Link>
                    <button
                        type="button"
                        onClick={() => open(null)}
                        className="inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        Add a charge type
                    </button>
                </div>
            </div>

            <DataTable
                columns={columns}
                rows={types}
                caption="Charge types"
                empty={
                    <EmptyState
                        title="No charge types yet."
                        description="Add one for anything you bill residents for other than rent."
                    />
                }
            />

            <ConfirmDialog
                open={editing !== null}
                title={editing === 'new' ? 'Add a charge type' : 'Edit charge type'}
                confirmLabel="Save"
                processing={form.processing}
                onCancel={() => { setEditing(null); form.reset(); }}
                onConfirm={save}
            >
                <FormField
                    label="Name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    error={form.errors.name}
                    maxLength={80}
                    hint="What you will pick from the list. For example, “Garbage collection”."
                    required
                />

                <FormField label="Kind" error={form.errors.category} required>
                    <select
                        value={form.data.category}
                        onChange={(e) => form.setData('category', e.target.value)}
                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                    >
                        {Object.entries(categories).map(([value, label]) => (
                            <option key={value} value={value}>{label}</option>
                        ))}
                    </select>
                </FormField>

                <FormField
                    label="Wording on the resident's ledger"
                    value={form.data.default_description}
                    onChange={(e) => form.setData('default_description', e.target.value)}
                    error={form.errors.default_description}
                    maxLength={150}
                    hint="What the resident reads. Plain language — they will see this beside their rent."
                    required
                />

                <FormField
                    label="Default amount"
                    value={form.data.default_amount}
                    onChange={(e) => form.setData('default_amount', e.target.value)}
                    error={form.errors.default_amount}
                    type="number"
                    inputMode="decimal"
                    min="0"
                    step="0.01"
                    hint="A starting point — you can change it each time you charge."
                    required
                />

                <label className="mt-3 flex items-center gap-3 text-base">
                    <input
                        type="checkbox"
                        checked={form.data.active}
                        onChange={(e) => form.setData('active', e.target.checked)}
                        className="rounded border-gray-300 text-brand-600 focus:ring-brand-600"
                    />
                    Offer this when posting a charge
                </label>

                {editing !== 'new' && (
                    <p className="mt-3 text-sm text-gray-600">
                        Changing the amount here does not change what residents already on this
                        charge are billed. Use <strong>Post a charge</strong> to change them.
                    </p>
                )}
            </ConfirmDialog>

            <ConfirmDialog
                open={confirming !== null}
                title={`Remove ${confirming?.name}?`}
                confirmLabel="Remove"
                tone="danger"
                onCancel={() => setConfirming(null)}
                onConfirm={() =>
                    router.delete(`/admin/charges/types/${confirming.id}`, {
                        onFinish: () => setConfirming(null),
                    })
                }
            >
                This has never been used to charge anybody, so nothing is lost.
            </ConfirmDialog>
        </AdminLayout>
    );
}
