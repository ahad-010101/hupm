/**
 * A banner message.  [UI §8, §9, FS §18.3]
 *
 * Tone carries a matching icon and, for anything the user must act on,
 * role="alert" so it is announced rather than merely displayed.
 *
 * Copy rules that apply wherever this is used:
 *   - every error says what to do next (UI §8)
 *   - delinquency wording is neutral and actionable, never accusatory
 *   - "processing", never "failed", for a pending ACH payment
 */

const TONES = {
    info: { classes: 'bg-settled-bg text-settled-fg border-settled-border', glyph: 'ℹ', live: false },
    success: { classes: 'bg-credit-bg text-credit-fg border-credit-border', glyph: '✓', live: false },
    warning: { classes: 'bg-due-bg text-due-fg border-due-border', glyph: '!', live: true },
    error: { classes: 'bg-overdue-bg text-overdue-fg border-overdue-border', glyph: '!', live: true },
};

export default function Alert({ tone = 'info', title, children, action, className = '' }) {
    const { classes, glyph, live } = TONES[tone] ?? TONES.info;

    return (
        <div
            role={live ? 'alert' : 'status'}
            className={`flex gap-3 rounded-lg border p-4 ${classes} ${className}`}
        >
            <span aria-hidden="true" className="text-lg leading-none">
                {glyph}
            </span>
            <div className="flex-1">
                {title && <p className="text-base font-semibold">{title}</p>}
                {children && <div className="text-base">{children}</div>}
                {action && <div className="mt-2">{action}</div>}
            </div>
        </div>
    );
}
