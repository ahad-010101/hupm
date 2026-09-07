import { Head, Link, useForm } from '@inertiajs/react';
import VendorLayout from '@/Layouts/VendorLayout';
import Alert from '@/Components/Alert';
import FormField from '@/Components/FormField';
import StatusBadge from '@/Components/StatusBadge';

/**
 * One job.  [WP-44]
 *
 * The access details are the point of this screen. A contractor who arrives
 * without knowing there is a dog, or that nobody is in before four, is a second
 * visit and a resident taking another afternoon off work.
 *
 * Only the moves the state machine allows are offered — the same list an admin
 * gets, from the same source. A contractor does not get a second set of rules.
 */
export default function TicketShow({ ticket, nextStates = {}, history = [] }) {
    const update = useForm({ to: '', note: '' });

    const submit = (e) => {
        e.preventDefault();
        update.patch(`/work/${ticket.id}`, {
            preserveScroll: true,
            onSuccess: () => update.reset(),
        });
    };

    return (
        <VendorLayout header={ticket.ticket_number}>
            <Head title={`${ticket.ticket_number} — your job`} />

            <Link
                href="/work"
                className="mb-4 inline-block text-base underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            >
                ← All jobs
            </Link>

            {ticket.is_emergency && (
                <Alert tone="error" className="mb-4" title="Emergency">
                    This was reported as an emergency. If anybody is in danger, call 911 first.
                </Alert>
            )}

            <section className="mb-4 rounded-lg border border-gray-200 bg-white p-4">
                <h2 className="text-lg font-semibold text-gray-900">
                    {ticket.address}
                    {ticket.unit ? `, unit ${ticket.unit}` : ''}
                </h2>
                <p className="mt-1 text-base text-gray-700">{ticket.category}</p>
                <p className="mt-1">
                    <StatusBadge status={ticket.status} label={ticket.status_label} />
                </p>
                <p className="mt-3 text-base text-gray-800">{ticket.description}</p>
            </section>

            <section className="mb-4 rounded-lg border border-gray-200 bg-white p-4">
                <h2 className="mb-2 text-lg font-semibold text-gray-900">Getting in</h2>
                <dl className="space-y-2 text-base">
                    <div className="flex justify-between gap-4 border-b border-gray-100 pb-1">
                        <dt className="text-gray-600">Resident</dt>
                        <dd>{ticket.resident ?? '—'}</dd>
                    </div>
                    <div className="flex justify-between gap-4 border-b border-gray-100 pb-1">
                        <dt className="text-gray-600">Telephone</dt>
                        <dd>
                            {ticket.contact_phone ? (
                                <a
                                    href={`tel:${ticket.contact_phone.replace(/[^\d+]/g, '')}`}
                                    className="font-medium underline"
                                >
                                    {ticket.contact_phone}
                                </a>
                            ) : (
                                '—'
                            )}
                        </dd>
                    </div>
                    <div className="flex justify-between gap-4 border-b border-gray-100 pb-1">
                        <dt className="text-gray-600">Permission to enter</dt>
                        <dd>{ticket.permission_to_enter ? 'Yes' : 'No — arrange with the resident'}</dd>
                    </div>
                    <div className="flex justify-between gap-4 border-b border-gray-100 pb-1">
                        <dt className="text-gray-600">Best time</dt>
                        <dd>{ticket.best_access_time ?? '—'}</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-gray-600">Pets</dt>
                        <dd>{ticket.pets_present ? 'Yes — there is a pet at the property' : 'None reported'}</dd>
                    </div>
                </dl>
                {ticket.scheduled_at && (
                    <p className="mt-3 text-base font-medium text-gray-900">
                        Scheduled for {ticket.scheduled_at}
                    </p>
                )}
            </section>

            {Object.keys(nextStates).length > 0 && (
                <form onSubmit={submit} className="mb-4 rounded-lg border border-gray-200 bg-white p-4">
                    <h2 className="mb-2 text-lg font-semibold text-gray-900">Update the office</h2>

                    <FormField label="What is happening?" error={update.errors.to} required>
                        <select
                            value={update.data.to}
                            onChange={(e) => update.setData('to', e.target.value)}
                            required
                            className="block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-brand-600 focus:ring-brand-600"
                        >
                            <option value="">Choose…</option>
                            {Object.entries(nextStates).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                    </FormField>

                    <FormField
                        label="Anything to add?"
                        value={update.data.note}
                        onChange={(e) => update.setData('note', e.target.value)}
                        error={update.errors.note}
                        maxLength={2000}
                        hint="Optional. The office reads this."
                    />

                    <button
                        type="submit"
                        disabled={update.processing || !update.data.to}
                        className="mt-2 inline-flex min-h-touch w-full items-center justify-center rounded-md bg-brand-600 px-4 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-60 sm:w-auto"
                    >
                        {update.processing ? 'Sending…' : 'Send update'}
                    </button>
                </form>
            )}

            {history.length > 0 && (
                <section className="rounded-lg border border-gray-200 bg-white p-4">
                    <h2 className="mb-2 text-lg font-semibold text-gray-900">History</h2>
                    <ul className="space-y-1 text-base text-gray-700">
                        {history.map((event) => (
                            <li key={event.id}>
                                {event.to} — {event.at}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </VendorLayout>
    );
}
