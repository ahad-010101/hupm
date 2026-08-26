import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * Property create/edit.  [D-19, FR-NTF-03]
 *
 * The cascade and the county fallback are rules that exist only in React. The
 * PHP suite can prove the endpoint returns Georgia's 159 counties; only this can
 * prove the form offers them as a list, and offers a text box instead for a
 * state we hold none for.
 */

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    useForm: (initial) => {
        const [data, set] = mockFormState(initial);

        return {
            data,
            setData: set,
            post: vi.fn(),
            patch: vi.fn(),
            processing: false,
            errors: {},
        };
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

// A stand-in for Inertia's useForm that behaves like the real one: setData
// takes either (key, value) or an updater over the whole object.
let formState;
let setFormState;

function mockFormState(initial) {
    const { useState } = React;
    const [data, set] = useState(initial);

    formState = data;
    setFormState = set;

    const setData = (keyOrUpdater, value) =>
        typeof keyOrUpdater === 'function'
            ? set(keyOrUpdater)
            : set((current) => ({ ...current, [keyOrUpdater]: value }));

    return [data, setData];
}

const React = await import('react');
const { default: Form } = await import('@/Pages/Admin/Properties/Form');

const GEORGIA_COUNTIES = ['Cobb', 'DeKalb', 'Fulton'];

function props(overrides = {}) {
    return {
        property: null,
        countries: [
            { iso2: 'US', name: 'United States' },
            { iso2: 'CA', name: 'Canada' },
        ],
        states: [{ id: 1, name: 'Georgia' }],
        cities: [{ id: 1, name: 'Atlanta' }],
        counties: GEORGIA_COUNTIES,
        selectedStateId: 1,
        ...overrides,
    };
}

beforeEach(() => {
    global.fetch = vi.fn(() =>
        Promise.resolve({ ok: true, json: () => Promise.resolve({ states: [], cities: [], counties: [] }) }),
    );
});

describe('the address cascade', () => {
    it('asks for a country', () => {
        render(<Form {...props()} />);

        expect(screen.getByLabelText(/Country/)).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'United States' })).toBeInTheDocument();
    });

    it('keeps state and city below it', () => {
        render(<Form {...props()} />);

        expect(screen.getByLabelText(/State/)).toBeInTheDocument();
        expect(screen.getByLabelText(/City/)).toBeInTheDocument();
    });

    it('warns at the moment of choosing, not when the alerts never arrive', () => {
        render(<Form {...props()} />);

        expect(screen.queryByText(/Weather alerts are US only/)).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText(/Country/), { target: { value: 'CA' } });

        expect(screen.getByText(/Weather alerts are US only/)).toBeInTheDocument();
    });
});

describe('county', () => {
    it('is a list where we hold one, so nobody has to spell DeKalb', () => {
        render(<Form {...props()} />);

        const county = screen.getByLabelText(/County/);

        expect(county.tagName).toBe('SELECT');
        expect(screen.getByRole('option', { name: 'DeKalb' })).toBeInTheDocument();
    });

    it('offers an explicit way to record none', () => {
        render(<Form {...props()} />);

        // Not a blank first option that reads as "you forgot this one". The
        // county is genuinely optional; leaving it out is a choice.
        expect(screen.getByRole('option', { name: 'No county recorded' })).toBeInTheDocument();
    });

    it('falls back to a text box for a state we hold no list for', () => {
        render(<Form {...props({ counties: [] })} />);

        const county = screen.getByLabelText(/County/);

        // A disabled empty select would mean a property outside Georgia could
        // never record a county, and would then match every state-wide alert.
        expect(county.tagName).toBe('INPUT');
    });

    it('says why it matters, since it looks like a formality', () => {
        render(<Form {...props()} />);

        expect(screen.getByText(/weather alerts are matched by county/i)).toBeInTheDocument();
    });
});
