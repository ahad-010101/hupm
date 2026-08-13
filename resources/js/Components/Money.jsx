/**
 * Renders a monetary amount.  [UI §8, §7; INVARIANT I-10]
 *
 * The backend serialises App\Support\Money as a decimal STRING ("300.00"),
 * never a number, and this component keeps it that way: formatting is string
 * manipulation, with no parseFloat and no Number() anywhere on the path.
 *
 * That is not pedantry. JavaScript numbers are IEEE-754 doubles — the same
 * representation the PHP side refuses — so parsing "300.00" into a number to
 * format it would reintroduce, at the last possible moment, exactly the drift
 * the whole money layer exists to prevent.
 *
 * Two rules from the content spec are enforced here rather than left to call
 * sites, because they are easy to forget and visible to tenants:
 *
 *   1. Currency always shows the symbol and two decimals (UI §8).
 *   2. A negative BALANCE renders as "Credit $X" in green, never as a minus
 *      figure (UI §7, §8) — pass `balance` to opt into this. A ledger row's
 *      signed amount is not a balance, so it stays "-$300.00".
 *
 * Status is never colour alone (UI §9): the credit case carries the word
 * "Credit", so it survives greyscale and screen readers.
 */

const AMOUNT_PATTERN = /^(-?)(\d+)(?:\.(\d{1,2}))?$/;

/**
 * Format a decimal string as currency, without ever creating a number.
 *
 * @param {string} value  A decimal string, e.g. "300", "300.5", "-1234.56".
 * @returns {{ text: string, negative: boolean }}
 */
export function formatMoney(value) {
    const match = String(value).trim().replace(/[$,\s]/g, '').match(AMOUNT_PATTERN);

    if (!match) {
        throw new Error(
            `<Money> received "${value}", which is not a decimal amount. ` +
                'Pass the string the backend serialised, not a computed number.',
        );
    }

    const [, sign, whole, fraction = ''] = match;
    const cents = fraction.padEnd(2, '0');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return { text: `$${grouped}.${cents}`, negative: sign === '-' && !/^0+$/.test(whole + cents) };
}

export default function Money({ value, balance = false, className = '', ...props }) {
    if (value === null || value === undefined) {
        // An unknown balance is never rendered as $0.00 — that is a specific
        // claim about someone's account, and a wrong one.
        return (
            <span className={`text-gray-500 ${className}`} {...props}>
                —
            </span>
        );
    }

    if (typeof value === 'number' && import.meta.env.DEV) {
        throw new Error(
            `<Money> received the number ${value}. Money crosses the wire as a string; ` +
                'a number here means it was computed in floating point somewhere upstream (I-10).',
        );
    }

    const { text, negative } = formatMoney(value);

    if (balance && negative) {
        return (
            <span className={`font-semibold text-credit-fg ${className}`} {...props}>
                Credit {text}
            </span>
        );
    }

    return (
        <span
            className={`tabular-nums ${negative ? 'text-overdue-fg' : ''} ${className}`}
            {...props}
        >
            {negative ? `-${text}` : text}
        </span>
    );
}
