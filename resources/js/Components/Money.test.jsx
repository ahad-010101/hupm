import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Money, { formatMoney } from '@/Components/Money';

/*
 * <Money>  [WP-05 DoD, UI §7 §8, INVARIANT I-10]
 *
 * The DoD requires that <Money> cannot render an unformatted or single-decimal
 * figure. These tests are that guarantee.
 */

describe('formatMoney', () => {
    it.each([
        ['300.00', '$300.00'],
        ['300', '$300.00'],
        ['300.5', '$300.50'],
        ['0', '$0.00'],
        ['0.07', '$0.07'],
        ['1234.56', '$1,234.56'],
        ['1234567.89', '$1,234,567.89'],
        ['-42.07', '$42.07'],
        ['$1,234.56', '$1,234.56'],
    ])('formats %s as %s', (input, expected) => {
        expect(formatMoney(input).text).toBe(expected);
    });

    it('never emits a single-decimal or bare figure', () => {
        for (const input of ['5', '5.1', '5.10', '0', '99999.9']) {
            expect(formatMoney(input).text).toMatch(/^\$[\d,]+\.\d{2}$/);
        }
    });

    it('rejects anything that is not a decimal amount', () => {
        for (const bad of ['', 'abc', '1.2.3', '--5', 'NaN', 'Infinity']) {
            expect(() => formatMoney(bad)).toThrow();
        }
    });

    it('does not treat negative zero as negative', () => {
        // "-0.00" is a rounding artefact, not a credit. Rendering it as
        // "Credit $0.00" would be nonsense on a tenant's dashboard.
        expect(formatMoney('-0.00').negative).toBe(false);
    });

    it('preserves precision a float would lose', () => {
        // 0.1 + 0.2 in IEEE-754 is 0.30000000000000004. Because nothing here
        // parses to a number, a value that has never been a float stays exact.
        expect(formatMoney('9007199254740993.99').text).toBe('$9,007,199,254,740,993.99');
    });
});

describe('<Money>', () => {
    it('renders a charge with symbol and two decimals', () => {
        render(<Money value="300.5" />);
        expect(screen.getByText('$300.50')).toBeInTheDocument();
    });

    it('renders a signed ledger amount as a minus figure', () => {
        // A ledger row's amount is signed data, not a balance.
        render(<Money value="-300.00" />);
        expect(screen.getByText('-$300.00')).toBeInTheDocument();
    });

    it('UI §7 renders a negative balance as a credit, never as a minus figure', () => {
        render(<Money value="-125.00" balance />);

        expect(screen.getByText(/Credit \$125\.00/)).toBeInTheDocument();
        expect(screen.queryByText(/-\$125\.00/)).not.toBeInTheDocument();
    });

    it('marks a credit with a word, not colour alone', () => {
        // UI §9: status is never conveyed by colour alone. "Credit" survives
        // greyscale and a screen reader; green does not.
        const { container } = render(<Money value="-125.00" balance />);

        expect(container.textContent).toContain('Credit');
    });

    it('shows a zero balance as $0.00, not as a credit', () => {
        render(<Money value="0.00" balance />);

        expect(screen.getByText('$0.00')).toBeInTheDocument();
        expect(screen.queryByText(/Credit/)).not.toBeInTheDocument();
    });

    it('renders an unknown amount as a dash, never as $0.00', () => {
        // A missing balance is not a zero balance. Rendering $0.00 makes a
        // specific and possibly wrong claim about someone's account.
        render(<Money value={null} />);

        expect(screen.getByText('—')).toBeInTheDocument();
        expect(screen.queryByText('$0.00')).not.toBeInTheDocument();
    });

    it('I-10 rejects a number, because money crosses the wire as a string', () => {
        expect(() => render(<Money value={300.5} />)).toThrow(/number/i);
    });
});
