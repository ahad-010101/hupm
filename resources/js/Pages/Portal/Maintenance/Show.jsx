import { Head, Link, useForm } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import Alert from '@/Components/Alert';
import StatusBadge from '@/Components/StatusBadge';

/**
 * One repair request, as the resident sees it.  [UI §3.5, API-POR-13/14]
 *
 * AC-MNT-09: the contractor's name is here because that is who will knock on
 * the door. What the repair cost is not — no invoice, no figure, nothing that
 * would let one be inferred. The props do not carry it.
 */
export default function Show({ ticket, events = [], photos = [], flash = {} }) {
    const confirm = useForm({});

    return (
        <PortalLayout header={`Request ${ticket.ticket_number}`}>
            <Head title={`Request ${ticket.ticket_number}`} />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {flash.error && <Alert tone="error" className="mb-4">{flash.error}</Alert>}

            <section className="mb-4 rounded-lg border border-gray-200 bg-white p-5">
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge
                        status={ticket.status}
                        label={ticket.status_label}
                        tone={ticket.is_open ? 'due' : 'settled'}
                    />
                    {ticket.is_emergency && <StatusBadge status="urgent" label="Urgent" tone="overdue" />}
                </div>

                <h2 className="mt-3 text-lg font-semibold text-gray-900">{ticket.category}</h2>
                <p className="mt-1 whitespace-pre-line text-base text-gray-700">{ticket.description}</p>

                <dl className="mt-4 space-y-1 text-base">
                    <div className="flex justify-between gap-4">
                        <dt className="text-gray-600">Reported</dt>
                        <dd>{ticket.submitted_on}</dd>
                    </div>
                    {ticket.date_began && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-gray-600">Started</dt>
                            <dd>{ticket.date_began}</dd>
                        </div>
                    )}
                    {ticket.scheduled_for && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-gray-600">Visit booked</dt>
                            <dd className="font-medium">{ticket.scheduled_for}</dd>
                        </div>
                    )}
                    {ticket.contractor && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-gray-600">Contractor</dt>
                            <dd>
                                {ticket.contractor}
                                {ticket.contractor_trade && ` (${ticket.contractor_trade})`}
                            </dd>
                        </div>
                    )}
                    <div className="flex justify-between gap-4">
                        <dt className="text-gray-600">May we enter</dt>
                        <dd>{ticket.permission_to_enter ? 'Yes' : 'No'}</dd>
                    </div>
                </dl>

                {photos.length > 0 && (
                    <p className="mt-3 text-sm text-gray-600">
                        {photos.length} {photos.length === 1 ? 'file' : 'files'} attached.
                    </p>
                )}
            </section>

            {/* AC-MNT-06. The one action a tenant has, and the ordinary way a
                ticket closes. */}
            {ticket.can_confirm && (
                <section className="mb-4 rounded-lg border border-brand-600 bg-brand-50 p-5">
                    <h2 className="text-lg font-semibold text-gray-900">Is it fixed?</h2>
                    <p className="mt-1 text-base text-gray-700">
                        The work has been reported as done. Please confirm, or tell us if it is not
                        right — we would rather come back than close it wrongly.
                    </p>
                    <button
                        type="button"
                        disabled={confirm.processing}
                        onClick={() => confirm.post(`/portal/maintenance/${ticket.id}/confirm`)}
                        className="mt-3 inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 disabled:opacity-60 sm:w-auto"
                    >
                        {confirm.processing ? 'Confirming…' : 'Yes, it is fixed'}
                    </button>
                </section>
            )}

            {ticket.close_reason && (
                <Alert tone="info" className="mb-4" title="Why this was closed">
                    {ticket.close_reason}
                </Alert>
            )}

            <section className="rounded-lg border border-gray-200 bg-white p-5">
                <h2 className="text-lg font-semibold text-gray-900">Progress</h2>
                <ol className="mt-3 space-y-3">
                    {events.map((event) => (
                        <li key={event.id} className="border-l-2 border-gray-200 pl-3">
                            <span className="block text-base font-medium text-gray-900">{event.status}</span>
                            {event.note && <span className="block text-base text-gray-700">{event.note}</span>}
                            <span className="block text-sm text-gray-600">{event.at}</span>
                        </li>
                    ))}
                </ol>
            </section>

            <Link
                href="/portal/maintenance"
                className="mt-4 inline-block text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            >
                All your requests
            </Link>
        </PortalLayout>
    );
}
