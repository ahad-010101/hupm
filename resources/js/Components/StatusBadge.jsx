/**
 * A status pill.  [UI §9]
 *
 * Status is never conveyed by colour alone — every badge carries a text label
 * and a glyph, so it survives greyscale printing, colour blindness and a screen
 * reader. The glyph is decorative and hidden from assistive tech; the label
 * does the work.
 *
 * Tenant-facing wording is deliberately not the database value. A pending ACH
 * payment reads "Processing", never "Pending" and never "Failed" (UI §8) —
 * ACH takes 2–5 business days, and a tenant told their payment failed will pay
 * twice.
 */

const TONES = {
    credit: 'bg-credit-bg text-credit-fg border-credit-border',
    due: 'bg-due-bg text-due-fg border-due-border',
    overdue: 'bg-overdue-bg text-overdue-fg border-overdue-border',
    settled: 'bg-settled-bg text-settled-fg border-settled-border',
    neutral: 'bg-gray-100 text-gray-700 border-gray-300',
};

/**
 * Ledger and payment statuses, mapped to tenant-safe wording.
 * Admin screens may pass an explicit label to show the raw state instead.
 */
const PRESETS = {
    pending: { tone: 'due', label: 'Processing', glyph: '◷' },
    posted: { tone: 'neutral', label: 'Posted', glyph: '•' },
    cleared: { tone: 'settled', label: 'Cleared', glyph: '✓' },
    settled: { tone: 'settled', label: 'Settled', glyph: '✓' },
    returned: { tone: 'overdue', label: 'Returned', glyph: '↩' },
    void: { tone: 'neutral', label: 'Void', glyph: '⊘' },
    current: { tone: 'credit', label: 'Current', glyph: '✓' },
    management_review: { tone: 'overdue', label: 'Management review', glyph: '!' },
    active: { tone: 'credit', label: 'Active', glyph: '✓' },
    ended: { tone: 'neutral', label: 'Ended', glyph: '•' },
    draft: { tone: 'neutral', label: 'Draft', glyph: '•' },
    vacant: { tone: 'due', label: 'Vacant', glyph: '○' },
    occupied: { tone: 'credit', label: 'Occupied', glyph: '●' },
    off_market: { tone: 'neutral', label: 'Off market', glyph: '⊘' },
    invited: { tone: 'due', label: 'Invited', glyph: '✉' },
    suspended: { tone: 'overdue', label: 'Suspended', glyph: '⊘' },
    not_deliverable: { tone: 'overdue', label: 'No email on file', glyph: '!' },
};

export default function StatusBadge({ status, label, tone, className = '' }) {
    const preset = PRESETS[status] ?? { tone: 'neutral', label: status, glyph: '•' };
    const resolvedTone = tone ?? preset.tone;
    const resolvedLabel = label ?? preset.label;

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-sm font-medium ${TONES[resolvedTone]} ${className}`}
        >
            <span aria-hidden="true">{preset.glyph}</span>
            {resolvedLabel}
        </span>
    );
}
