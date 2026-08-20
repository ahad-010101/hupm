import { Link, useForm } from '@inertiajs/react';
import EmptyState from '@/Components/EmptyState';

/**
 * The exceptions panel.  [UI §3.7, AC-ADM-02, FR-ADM-01]
 *
 * One component for both the dashboard panel and the exceptions page, so the
 * two cannot drift into showing different things — the dashboard passes a
 * `limit`, and that is the only difference between them.
 *
 * Every row is a link to where the thing gets fixed, and severity is carried by
 * a word as well as a colour (UI §9). The left border is decorative; the label
 * beside it is what survives greyscale, colour blindness and a screen reader.
 */

/** Order matches ExceptionFeed's urgency ordering — do not sort by date. */
const KINDS = {
    emergency_ticket: { label: 'Emergency', tone: 'border-l-overdue-fg bg-overdue-bg', glyph: '!' },
    returned_payment: { label: 'Returned', tone: 'border-l-overdue-fg bg-overdue-bg', glyph: '↩' },
    failed_autopay: { label: 'Autopay failed', tone: 'border-l-overdue-fg bg-white', glyph: '⊘' },
    noc_flag: { label: 'Bank details', tone: 'border-l-due-fg bg-white', glyph: '⚑' },
    unmatched_payment: { label: 'Unmatched', tone: 'border-l-due-fg bg-white', glyph: '?' },
    management_review: { label: 'Management review', tone: 'border-l-due-fg bg-white', glyph: '◉' },
};

function AcknowledgeButton({ item }) {
    const form = useForm({ kind: item.kind, subject_id: item.subject_id, note: '' });

    const submit = () =>
        form.post('/admin/exceptions/acknowledge', { preserveScroll: true });

    return (
        <button
            type="button"
            onClick={submit}
            disabled={form.processing}
            className="min-h-touch shrink-0 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:opacity-50"
        >
            {form.processing ? 'Saving…' : 'Acknowledge'}
        </button>
    );
}

function sinceLabel(iso) {
    if (!iso) return null;

    // Formatted from the ISO string the server sent, with no arithmetic: the
    // browser's clock and the company's timezone are not the same thing (D-07),
    // and "3 days ago" computed locally is wrong for five hours every night.
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function ExceptionList({ items = [], limit = null, className = '' }) {
    const shown = limit ? items.slice(0, limit) : items;
    const hidden = items.length - shown.length;

    if (items.length === 0) {
        return (
            <EmptyState
                className={className}
                title="Nothing needs attention"
                description="No returned payments, no failed autopay, no untriaged emergencies. This is the state to aim for."
            />
        );
    }

    return (
        <div className={className}>
            <ul className="space-y-2">
                {shown.map((item) => {
                    const kind = KINDS[item.kind] ?? { label: item.kind, tone: 'border-l-gray-400 bg-white', glyph: '•' };

                    return (
                        <li
                            key={`${item.kind}:${item.subject_id}`}
                            className={`flex flex-col gap-2 rounded-md border border-gray-200 border-l-4 p-3 sm:flex-row sm:items-center sm:gap-4 ${kind.tone}`}
                        >
                            <span className="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-gray-900">
                                <span aria-hidden="true">{kind.glyph}</span>
                                {kind.label}
                            </span>

                            <Link
                                href={item.href}
                                className="min-w-0 flex-1 rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            >
                                <span className="block text-base font-medium text-gray-900">{item.title}</span>
                                <span className="block text-base text-gray-600">{item.detail}</span>
                            </Link>

                            {item.since && (
                                <span className="shrink-0 text-sm text-gray-500">{sinceLabel(item.since)}</span>
                            )}

                            {item.acknowledgeable && <AcknowledgeButton item={item} />}
                        </li>
                    );
                })}
            </ul>

            {hidden > 0 && (
                <p className="mt-3 text-base">
                    <Link
                        href="/admin/exceptions"
                        className="font-medium text-brand-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                    >
                        {hidden} more {hidden === 1 ? 'item needs' : 'items need'} attention
                    </Link>
                </p>
            )}
        </div>
    );
}
