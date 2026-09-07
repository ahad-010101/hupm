import { Head, Link } from '@inertiajs/react';
import VendorLayout from '@/Layouts/VendorLayout';
import EmptyState from '@/Components/EmptyState';
import StatusBadge from '@/Components/StatusBadge';

/**
 * A contractor's job list.  [WP-44]
 *
 * Open work first and closed work below it. A finished job is history, not
 * something to do, and a list that mixes the two makes somebody read every row
 * to find the three that matter.
 */
function JobCard({ ticket }) {
    return (
        <li className="rounded-lg border border-gray-200 bg-white p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <Link
                    href={`/work/${ticket.id}`}
                    className="text-base font-semibold text-gray-900 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                >
                    {ticket.address}
                    {ticket.unit ? `, unit ${ticket.unit}` : ''}
                </Link>
                {/* Never colour alone — the word is the signal (UI §9). */}
                {ticket.is_emergency && (
                    <StatusBadge status="emergency" label="Emergency" tone="overdue" />
                )}
            </div>

            <p className="mt-1 text-base text-gray-700">{ticket.category}</p>

            <p className="mt-1 text-sm text-gray-600">
                {ticket.ticket_number} · <StatusBadge status={ticket.status} label={ticket.status_label} />
                {ticket.scheduled_at && ` · ${ticket.scheduled_at}`}
            </p>
        </li>
    );
}

export default function Tickets({ vendor, open = [], done = [] }) {
    return (
        <VendorLayout vendor={vendor} header="Your jobs">
            <Head title="Your jobs" />

            {open.length === 0 ? (
                <EmptyState
                    title="Nothing assigned right now."
                    description="When the office assigns you a job it will appear here."
                />
            ) : (
                <ul className="space-y-3">
                    {open.map((ticket) => (
                        <JobCard key={ticket.id} ticket={ticket} />
                    ))}
                </ul>
            )}

            {done.length > 0 && (
                <section className="mt-8">
                    <h2 className="mb-3 text-lg font-semibold text-gray-900">Finished</h2>
                    <ul className="space-y-3">
                        {done.map((ticket) => (
                            <JobCard key={ticket.id} ticket={ticket} />
                        ))}
                    </ul>
                </section>
            )}
        </VendorLayout>
    );
}
