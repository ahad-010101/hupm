import { Head } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import ExceptionList from '@/Components/ExceptionList';

/**
 * Everything that needs attention.  [FR-ADM-01, AC-ADM-02]
 *
 * The dashboard shows the first five; this shows all of them, in the same
 * component so the two can never disagree about what an exception looks like or
 * which ones can be acknowledged.
 */

const KIND_LABELS = {
    emergency_ticket: 'Emergency maintenance',
    returned_payment: 'Returned payments',
    failed_autopay: 'Failed autopay',
    noc_flag: 'Bank details to update',
    unmatched_payment: 'Unmatched payments',
    management_review: 'New Management Review',
};

export default function Exceptions({ exceptions = [], summary }) {
    const counts = Object.entries(summary?.by_kind ?? {});

    return (
        <AdminLayout header="Needs attention">
            <Head title="Needs attention" />

            {counts.length > 0 && (
                <ul className="mb-4 flex flex-wrap gap-2">
                    {counts.map(([kind, count]) => (
                        <li
                            key={kind}
                            className="rounded-full border border-gray-300 bg-white px-3 py-1 text-sm text-gray-700"
                        >
                            <span className="font-semibold text-gray-900">{count}</span>{' '}
                            {KIND_LABELS[kind] ?? kind}
                        </li>
                    ))}
                </ul>
            )}

            <ExceptionList items={exceptions} />

            {exceptions.length > 0 && (
                <p className="mt-4 max-w-prose text-base text-gray-600">
                    Acknowledging a returned payment or a failed autopay takes it off this list — the
                    payment itself is unchanged, and it stays on the resident's ledger. Everything else
                    here clears when the underlying thing is dealt with.
                </p>
            )}
        </AdminLayout>
    );
}
