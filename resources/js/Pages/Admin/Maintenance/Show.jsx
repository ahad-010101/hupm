import { Head, Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import Alert from '@/Components/Alert';
import FormField from '@/Components/FormField';
import StatusBadge from '@/Components/StatusBadge';

/**
 * One ticket, and everything that can be done to it.  [UI §3.5, API-ADM-22…25]
 *
 * The status dropdown offers **only the moves that are legal from here**
 * (AC-MNT-08): the state machine decides, and an action that would be refused
 * is not on screen at all. Offering "Closed" from "Submitted" and then
 * rejecting it teaches the manager to distrust the form.
 */
export default function Show({ ticket, nextStates = {}, vendors = [], events = [], attachments = [], flash = {}, errors = {} }) {
    const move = useForm({ to: '', note: '', close_reason: '', scheduled_at: ticket.scheduled_at ?? '' });
    const assign = useForm({ vendor_id: ticket.vendor_id ?? '', note: '' });

    const closing = move.data.to === 'closed';
    const scheduling = move.data.to === 'scheduled';

    return (
        <AdminLayout header={`Ticket ${ticket.ticket_number}`}>
            <Head title={`Ticket ${ticket.ticket_number}`} />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {errors.to && <Alert tone="error" className="mb-4" title="That move is not allowed">{errors.to}</Alert>}

            {ticket.is_emergency && (
                <Alert tone="error" className="mb-4" title="Marked urgent by the resident">
                    Treat this ahead of the rest of the queue.
                </Alert>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <section className="rounded-lg border border-gray-200 bg-white p-5">
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge status={ticket.status} label={ticket.status_label} tone="neutral" />
                            <span className="text-sm text-gray-600">Reported {ticket.submitted_on}</span>
                        </div>

                        <h2 className="mt-3 text-lg font-semibold text-gray-900">{ticket.category}</h2>
                        <p className="mt-1 whitespace-pre-line text-base text-gray-700">{ticket.description}</p>

                        <dl className="mt-4 grid gap-x-6 gap-y-1 text-base sm:grid-cols-2">
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-600">Resident</dt>
                                <dd>
                                    <Link href={`/admin/ledger/${ticket.tenant_id}`} className="underline">
                                        {ticket.tenant}
                                    </Link>
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-600">Unit</dt>
                                <dd>{ticket.unit ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-600">Contact</dt>
                                <dd>
                                    {ticket.preferred_contact === 'phone' && ticket.tenant_phone ? (
                                        <a href={`tel:${ticket.tenant_phone.replace(/[^\d+]/g, '')}`} className="underline">
                                            {ticket.tenant_phone}
                                        </a>
                                    ) : (
                                        'Email'
                                    )}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-600">May enter</dt>
                                <dd>{ticket.permission_to_enter ? 'Yes' : 'No'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-600">Pets</dt>
                                <dd>{ticket.pets_present ? 'Yes' : 'No'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-gray-600">Best time</dt>
                                <dd>{ticket.best_access_time ?? '—'}</dd>
                            </div>
                            {ticket.date_began && (
                                <div className="flex justify-between gap-4">
                                    <dt className="text-gray-600">Started</dt>
                                    <dd>{ticket.date_began}</dd>
                                </div>
                            )}
                        </dl>

                        {attachments.length > 0 && (
                            <ul className="mt-4 text-base">
                                {attachments.map((file) => (
                                    <li key={file.id}>
                                        {file.original_filename}
                                        <span className="ml-2 text-sm text-gray-600">
                                            {file.kind === 'invoice' ? 'invoice — not shown to the resident' : file.kind.replace('_', ' ')}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="mt-4 rounded-lg border border-gray-200 bg-white p-5">
                        <h2 className="text-lg font-semibold text-gray-900">History</h2>
                        <ol className="mt-3 space-y-3">
                            {events.map((event) => (
                                <li key={event.id} className="border-l-2 border-gray-200 pl-3">
                                    <span className="block text-base font-medium text-gray-900">
                                        {event.from ? `${event.from} → ${event.to}` : event.to}
                                    </span>
                                    {event.note && <span className="block text-base text-gray-700">{event.note}</span>}
                                    <span className="block text-sm text-gray-600">
                                        {event.actor} · {event.at}
                                        {!event.visible_to_tenant && ' · internal'}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </section>
                </div>

                <div>
                    {Object.keys(nextStates).length > 0 ? (
                        <section className="rounded-lg border border-gray-200 bg-white p-5">
                            <h2 className="mb-3 text-lg font-semibold text-gray-900">Move this on</h2>

                            <FormField label="To" error={move.errors.to} required>
                                <select
                                    value={move.data.to}
                                    onChange={(e) => move.setData('to', e.target.value)}
                                    className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                                >
                                    <option value="">Choose…</option>
                                    {Object.entries(nextStates).map(([value, label]) => (
                                        <option key={value} value={value}>{label}</option>
                                    ))}
                                </select>
                            </FormField>

                            {scheduling && (
                                <FormField
                                    label="When"
                                    type="datetime-local"
                                    value={move.data.scheduled_at}
                                    onChange={(e) => move.setData('scheduled_at', e.target.value)}
                                    error={move.errors.scheduled_at}
                                    required
                                />
                            )}

                            {closing && (
                                // BR-24 / AC-MNT-07. The resident has not
                                // confirmed, so the reason is the record of why
                                // it was closed anyway.
                                <FormField
                                    label="Why are you closing it?"
                                    value={move.data.close_reason}
                                    onChange={(e) => move.setData('close_reason', e.target.value)}
                                    error={move.errors.close_reason}
                                    hint="The resident has not confirmed. This is kept on the ticket."
                                    maxLength={500}
                                    required
                                />
                            )}

                            <FormField
                                label="Note"
                                value={move.data.note}
                                onChange={(e) => move.setData('note', e.target.value)}
                                error={move.errors.note}
                                hint="Optional. Kept on the history."
                                maxLength={2000}
                            />

                            <button
                                type="button"
                                disabled={move.processing || !move.data.to}
                                onClick={() => move.patch(`/admin/maintenance/${ticket.id}/status`, { preserveScroll: true })}
                                className="inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
                            >
                                {move.processing ? 'Saving…' : 'Update'}
                            </button>
                        </section>
                    ) : (
                        <Alert tone="info" title="This ticket is finished">
                            {ticket.close_reason ?? 'Nothing further to do. Open a new ticket if the fault returns.'}
                        </Alert>
                    )}

                    <section className="mt-4 rounded-lg border border-gray-200 bg-white p-5">
                        <h2 className="mb-3 text-lg font-semibold text-gray-900">Contractor</h2>

                        {ticket.vendor && (
                            <p className="mb-2 text-base text-gray-700">Currently {ticket.vendor}.</p>
                        )}

                        {vendors.length === 0 ? (
                            <p className="text-base text-gray-700">
                                No contractors on file yet.
                            </p>
                        ) : (
                            <>
                                <FormField label="Assign to" error={assign.errors.vendor_id}>
                                    <select
                                        value={assign.data.vendor_id}
                                        onChange={(e) => assign.setData('vendor_id', e.target.value)}
                                        className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                                    >
                                        <option value="">Choose…</option>
                                        {vendors.map((vendor) => (
                                            <option key={vendor.id} value={vendor.id}>
                                                {vendor.name}{vendor.trade ? ` — ${vendor.trade}` : ''}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>

                                <button
                                    type="button"
                                    disabled={assign.processing || !assign.data.vendor_id}
                                    onClick={() => assign.post(`/admin/maintenance/${ticket.id}/assign`, { preserveScroll: true })}
                                    className="inline-flex min-h-touch w-full items-center justify-center rounded-md border border-gray-300 px-4 text-base font-medium hover:bg-gray-50 disabled:opacity-60"
                                >
                                    {assign.processing ? 'Assigning…' : 'Assign'}
                                </button>

                                <p className="mt-2 text-sm text-gray-600">
                                    The resident is told who is coming, never what it costs.
                                </p>
                            </>
                        )}
                    </section>
                </div>
            </div>

            <Link
                href="/admin/maintenance"
                className="mt-4 inline-block text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            >
                Back to the queue
            </Link>
        </AdminLayout>
    );
}
