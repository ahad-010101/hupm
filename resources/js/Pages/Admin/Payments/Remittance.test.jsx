import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { assignedLines } from './Remittance';

/*
 * Remittance split — the payload the browser actually sends.  [WP-12, GATE Q-2]
 *
 * The PHP suite posts straight to the endpoint, so it cannot see this step.
 * That gap hid a real bug: `useForm().post(url, { data })` silently ignores the
 * data override, so the split never left the browser and the server was told
 * no tenants had been assigned anything.
 */

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }) => children,
    router: { get: vi.fn() },
    useForm: () => ({
        data: { housing_authority_id: 1, total: '', received_on: '2026-08-17', lines: [] },
        setData: vi.fn(),
        post: vi.fn(),
        transform: vi.fn(),
        processing: false,
        errors: {},
    }),
}));

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }) => children }));

describe('assignedLines', () => {
    const lines = [{ lease_id: 7 }, { lease_id: 8 }, { lease_id: 9 }];

    it('sends only the rows carrying money', () => {
        expect(assignedLines(lines, { 7: '700.00', 8: '', 9: '0.00' })).toEqual([
            { lease_id: 7, amount: '700.00' },
        ]);
    });

    it('sends the amount as the string the admin typed, never a number', () => {
        const [line] = assignedLines(lines, { 7: '845.86' });

        // A number here would have been through IEEE-754 on the way (I-10).
        expect(typeof line.amount).toBe('string');
        expect(line.amount).toBe('845.86');
    });

    it('drops a malformed figure rather than sending it', () => {
        expect(assignedLines(lines, { 7: 'abc', 8: '100.00' })).toEqual([
            { lease_id: 8, amount: '100.00' },
        ]);
    });
});

describe('the split tally', () => {
    const props = {
        authorities: [{ id: 1, name: 'DeKalb County HA', is_lump_sum: true }],
        authority: { id: 1, name: 'DeKalb County HA' },
        lines: [
            { lease_id: 7, tenant: 'Ada Cole', unit: 'Elm Court — unit 1', monthly: '700.00', outstanding: '700.00' },
            { lease_id: 8, tenant: 'Bo Reid', unit: 'Elm Court — unit 2', monthly: '300.00', outstanding: '300.00' },
        ],
        today: '2026-08-17',
        idempotencyKey: '00000000-0000-4000-8000-000000000000',
    };

    const renderPage = async () => {
        const { default: Remittance } = await import('./Remittance');

        return render(<Remittance {...props} />);
    };

    it('fills each row from what the tenant owes', async () => {
        await renderPage();

        fireEvent.click(screen.getByText('Suggest from outstanding'));

        expect(screen.getByLabelText('Amount to apply to Ada Cole')).toHaveValue('700.00');
        expect(screen.getByLabelText('Amount to apply to Bo Reid')).toHaveValue('300.00');
    });

    it('says nothing about balance until an amount is entered', async () => {
        await renderPage();

        // "Nothing entered yet" and "entered zero" are different states;
        // collapsing them made an empty form read "$0.00 of $0.00 · Balanced".
        expect(screen.getByText(/Enter the remittance amount/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Record remittance/ })).toBeDisabled();
    });
});
