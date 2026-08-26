import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * The audit browser.  [FR-AUD-01, API-ADM-41, WP-29]
 *
 * The PHP suite proves the controller sends the right props; it cannot prove
 * the page survives them. Everything below is a rule that lives only in React:
 * a null actor reading as "System", a change summary rendering as before → after
 * whichever shape the logger used, and a filter never stranding the reader on
 * page four of a different question.
 */

const routerGet = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, dangerouslySetInnerHTML, ...props }) => (
        <a {...props} dangerouslySetInnerHTML={dangerouslySetInnerHTML}>
            {children}
        </a>
    ),
    router: {
        get: (...args) => routerGet(...args),
    },
}));

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children, header }) => (
        <div>
            <h1>{header}</h1>
            {children}
        </div>
    ),
}));

const { default: Index } = await import('@/Pages/Admin/Audit/Index');

function props(overrides = {}) {
    return {
        logs: {
            data: [
                {
                    id: 2,
                    at: '2026-08-26T14:30:00+00:00',
                    actor: 'Marta',
                    actor_id: 3,
                    action: 'ledger.adjustment.posted',
                    subject_type: 'LedgerEntry',
                    subject_id: 91,
                    changes: { amount: '-25.00' },
                    ip_address: '203.0.113.4',
                },
                {
                    id: 1,
                    at: '2026-08-26T07:00:00+00:00',
                    actor: null,
                    actor_id: null,
                    action: 'fees.late.posted',
                    subject_type: 'LedgerEntry',
                    subject_id: 90,
                    changes: { status: { from: 'current', to: 'late' } },
                    ip_address: null,
                },
            ],
            total: 2,
            last_page: 1,
            links: [],
        },
        filters: {},
        actions: {
            ledger: ['ledger.adjustment.posted'],
            fees: ['fees.late.posted'],
        },
        subjectTypes: [{ value: 'App\\Models\\LedgerEntry', label: 'LedgerEntry' }],
        actors: [{ value: '3', label: 'Marta' }],
        timezone: 'America/New_York',
        ...overrides,
    };
}

beforeEach(() => {
    routerGet.mockClear();
});

describe('the trail', () => {
    it('names the person who acted', () => {
        render(<Index {...props()} />);

        expect(screen.getAllByText('Marta').length).toBeGreaterThan(0);
    });

    it('says System rather than leaving an overnight rule anonymous', () => {
        render(<Index {...props()} />);

        // "Nobody chose to charge you, a rule did" — only true if the row says so.
        expect(screen.getAllByText('System').length).toBeGreaterThan(0);
    });

    it('reads an action without a dictionary that could fall out of date', () => {
        render(<Index {...props()} />);

        expect(screen.getAllByText('Ledger').length).toBeGreaterThan(0);
        expect(screen.getAllByText('adjustment posted').length).toBeGreaterThan(0);
    });

    it('renders a transition as before → after, and a summary as itself', () => {
        render(<Index {...props()} />);

        // recordChange() stores { from, to }; record() stores a flat summary.
        // One column has to read both without the reader knowing the difference.
        //
        // The arrow carries an accessible name rather than standing alone: a
        // bare glyph is meaning conveyed by symbol only (UI §9).
        const transition = screen.getAllByLabelText('changed to')[0].parentElement;

        expect(transition.textContent).toContain('current');
        expect(transition.textContent).toContain('late');

        expect(screen.getAllByText('-25.00').length).toBeGreaterThan(0);
    });

    it('D-07 shows times in the company timezone, not the reader’s', () => {
        render(<Index {...props()} />);

        // 14:30 UTC is 10:30 in New York. A reader in Karachi must not see 19:30
        // and conclude the overnight job ran in the evening.
        expect(screen.getAllByText(/10:30/).length).toBeGreaterThan(0);
    });

    it('offers a subject filter labelled by the model, not its namespace', () => {
        render(<Index {...props()} />);

        expect(screen.getByRole('option', { name: 'LedgerEntry' })).toBeInTheDocument();
    });
});

describe('filtering', () => {
    it('narrows without carrying the page number of a different question', () => {
        render(<Index {...props({ filters: { action: 'fees.late.posted' } })} />);

        fireEvent.change(screen.getByLabelText('Who'), { target: { value: '3' } });

        expect(routerGet).toHaveBeenCalledWith(
            '/admin/audit',
            { action: 'fees.late.posted', actor: '3' },
            expect.anything(),
        );
    });

    it('drops a filter rather than sending it empty', () => {
        render(<Index {...props({ filters: { actor: '3', action: 'fees.late.posted' } })} />);

        fireEvent.change(screen.getByLabelText('Who'), { target: { value: '' } });

        // An empty parameter in the URL is a filter that looks set and is not.
        expect(routerGet).toHaveBeenCalledWith(
            '/admin/audit',
            { action: 'fees.late.posted' },
            expect.anything(),
        );
    });

    it('offers to clear only when something is set', () => {
        const { unmount } = render(<Index {...props()} />);
        expect(screen.queryByRole('button', { name: 'Clear filters' })).not.toBeInTheDocument();
        unmount();

        render(<Index {...props({ filters: { actor: 'system' } })} />);
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));

        expect(routerGet).toHaveBeenCalledWith('/admin/audit', {}, expect.anything());
    });

    it('says why the table is empty, and which kind of empty it is', () => {
        const empty = { data: [], total: 0, last_page: 1, links: [] };

        const { unmount } = render(<Index {...props({ logs: empty })} />);
        expect(screen.getByText('Nothing recorded yet.')).toBeInTheDocument();
        unmount();

        render(<Index {...props({ logs: empty, filters: { actor: 'system' } })} />);
        expect(screen.getByText('Nothing matches those filters.')).toBeInTheDocument();
    });
});

describe('reading a crowded row', () => {
    it('folds a long change summary away instead of burying the other rows', () => {
        const crowded = {
            data: [
                {
                    id: 5,
                    at: '2026-08-26T14:30:00+00:00',
                    actor: 'Marta',
                    actor_id: 3,
                    action: 'setting.changed',
                    subject_type: null,
                    subject_id: null,
                    changes: {
                        a: '1', b: '2', c: '3', d: '4', e: '5',
                    },
                    ip_address: null,
                },
            ],
            total: 1,
            last_page: 1,
            links: [],
        };

        render(<Index {...props({ logs: crowded })} />);

        expect(screen.getAllByText('5 fields').length).toBeGreaterThan(0);
    });

    it('shows a dash rather than an empty cell when there is nothing to show', () => {
        const bare = {
            data: [
                {
                    id: 6,
                    at: '2026-08-26T14:30:00+00:00',
                    actor: 'Marta',
                    actor_id: 3,
                    action: 'auth.login.success',
                    subject_type: null,
                    subject_id: null,
                    changes: null,
                    ip_address: null,
                },
            ],
            total: 1,
            last_page: 1,
            links: [],
        };

        render(<Index {...props({ logs: bare })} />);

        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    });
});
