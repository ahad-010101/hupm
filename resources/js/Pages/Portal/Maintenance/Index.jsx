import { Head, Link } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';
import Alert from '@/Components/Alert';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';

/** A resident's repair requests.  [UI §3.5, API-POR-10] */
function TicketCard({ ticket }) {
    return (
        <li>
            <Link
                href={`/portal/maintenance/${ticket.id}`}
                className="block rounded-lg border border-gray-200 bg-white p-4 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            >
                <div className="flex items-baseline justify-between gap-3">
                    <span className="text-base font-medium text-gray-900">{ticket.category}</span>
                    <span className="shrink-0 text-sm text-gray-600">{ticket.ticket_number}</span>
                </div>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    {/* Never colour alone (UI §9). */}
                    <StatusBadge
                        status={ticket.status}
                        label={ticket.status_label}
                        tone={ticket.is_open ? 'due' : 'settled'}
                    />
                    {ticket.is_emergency && (
                        <StatusBadge status="urgent" label="Urgent" tone="overdue" />
                    )}
                    <span className="text-sm text-gray-600">Reported {ticket.submitted_on}</span>
                </div>
            </Link>
        </li>
    );
}

export default function Index({ tickets = [], flash = {} }) {
    const open = tickets.filter((t) => t.is_open);
    const done = tickets.filter((t) => !t.is_open);

    return (
        <PortalLayout header="Repairs">
            <Head title="Repairs" />

            {flash.status && <Alert tone="success" className="mb-4">{flash.status}</Alert>}
            {flash.error && <Alert tone="error" className="mb-4">{flash.error}</Alert>}

            <Link
                href="/portal/maintenance/new"
                className="mb-4 inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 sm:w-auto"
            >
                Report a repair
            </Link>

            {tickets.length === 0 ? (
                <EmptyState
                    title="No repair requests yet."
                    description="Report anything that needs fixing and we will pick it up."
                />
            ) : (
                <>
                    {open.length > 0 && (
                        <>
                            <h2 className="mb-2 text-lg font-semibold text-gray-900">Open</h2>
                            <ul className="mb-6 space-y-2">
                                {open.map((t) => <TicketCard key={t.id} ticket={t} />)}
                            </ul>
                        </>
                    )}

                    {done.length > 0 && (
                        <>
                            <h2 className="mb-2 text-lg font-semibold text-gray-900">Finished</h2>
                            <ul className="space-y-2">
                                {done.map((t) => <TicketCard key={t.id} ticket={t} />)}
                            </ul>
                        </>
                    )}
                </>
            )}
        </PortalLayout>
    );
}
