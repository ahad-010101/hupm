import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * The page editor.  [WP-36, D-27]
 *
 * The PHP suite proves the routes and the catalogue. Only this can prove the
 * editor generates its whole form from that catalogue — which is the claim that
 * a new section type needs no React at all — and that reordering works from a
 * keyboard, because it is buttons rather than a drag library.
 */

const routerPost = vi.fn();
const routerDelete = vi.fn();
const formPatch = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    router: {
        post: (...args) => routerPost(...args),
        delete: (...args) => routerDelete(...args),
    },
    useForm: (initial) => {
        const [data, set] = React.useState(initial);

        return {
            data,
            setData: (key, value) => set((current) => ({ ...current, [key]: value })),
            patch: (...args) => formPatch(...args),
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

const React = await import('react');
const { default: Edit } = await import('@/Pages/Admin/Website/Edit');

const CATALOGUE = {
    hero: {
        label: 'Hero',
        blurb: 'The opening block.',
        fields: {
            heading: { label: 'Heading', input: 'text', max: 120 },
            primary_route: { label: 'Button goes to', input: 'route' },
        },
    },
    faq: {
        label: 'Questions',
        blurb: 'Questions that open and close.',
        fields: { heading: { label: 'Heading', input: 'text', max: 120 } },
        items: {
            label: 'Questions',
            min: 1,
            max: 8,
            fields: {
                question: { label: 'Question', input: 'text', max: 160 },
                answer: { label: 'Answer', input: 'textarea', max: 800 },
            },
        },
    },
};

function props(overrides = {}) {
    return {
        page: {
            slug: 'about',
            title: 'About us',
            nav_label: 'About',
            meta_description: '',
            is_published: true,
            show_in_nav: true,
            nav_position: 20,
            url: 'http://localhost/about',
        },
        sections: [
            { id: 1, type: 'hero', label: 'Hero', summary: 'We manage homes', is_enabled: true, payload: {} },
            { id: 2, type: 'faq', label: 'Questions', summary: 'Questions', is_enabled: false, payload: {} },
        ],
        catalogue: CATALOGUE,
        routeOptions: { login: 'Resident login', 'public.contact': 'Contact us' },
        flash: {},
        ...overrides,
    };
}

beforeEach(() => {
    routerPost.mockClear();
    routerDelete.mockClear();
    formPatch.mockClear();
});

describe('the section list', () => {
    it('names each section by its own words, not by its type', () => {
        render(<Edit {...props()} />);

        // "Hero, Questions, Text, Cards" tells an editor nothing about which one
        // they want to change.
        expect(screen.getByText('We manage homes')).toBeInTheDocument();
    });

    it('marks a hidden section as hidden', () => {
        render(<Edit {...props()} />);

        expect(screen.getAllByText('Hidden').length).toBeGreaterThan(0);
    });

    it('opens a page in a new tab rather than losing the editor’s place', () => {
        render(<Edit {...props()} />);

        const link = screen.getByRole('link', { name: /View this page/ });

        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    });
});

describe('reordering', () => {
    it('moves a section with a button, so it works from a keyboard', () => {
        render(<Edit {...props()} />);

        fireEvent.click(screen.getByLabelText('Move Questions up'));

        expect(routerPost).toHaveBeenCalledWith(
            '/admin/website/about/sections/2/move',
            { direction: 'up' },
            expect.anything(),
        );
    });

    it('disables the move that would go off the end of the list', () => {
        render(<Edit {...props()} />);

        expect(screen.getByLabelText('Move Hero up')).toBeDisabled();
        expect(screen.getByLabelText('Move Questions down')).toBeDisabled();

        expect(screen.getByLabelText('Move Hero down')).toBeEnabled();
    });
});

describe('the form comes from the catalogue', () => {
    it('renders a section’s fields without knowing what they are', () => {
        render(<Edit {...props()} />);

        fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[0]);

        // Neither of these field names appears anywhere in the component. A new
        // section type is a catalogue entry and a Blade partial, no React.
        expect(screen.getByLabelText('Heading')).toBeInTheDocument();
        expect(screen.getByLabelText('Button goes to')).toBeInTheDocument();
    });

    it('offers a button’s destinations as a list, never as a typed URL', () => {
        render(<Edit {...props()} />);

        fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[0]);

        const select = screen.getByLabelText('Button goes to');

        expect(select.tagName).toBe('SELECT');
        expect(screen.getByRole('option', { name: 'Resident login' })).toBeInTheDocument();
        // Blank is meaningful: it hides the button.
        expect(screen.getByRole('option', { name: 'No button' })).toBeInTheDocument();
    });

    it('adds and removes rows in a repeatable group', () => {
        render(<Edit {...props()} />);

        fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[1]);

        expect(screen.queryByLabelText('Question')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Add question/i }));

        expect(screen.getByLabelText('Question')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Remove' }));

        expect(screen.queryByLabelText('Question')).not.toBeInTheDocument();
    });

    it('says what a section is for before it is added', () => {
        render(<Edit {...props()} />);

        fireEvent.change(screen.getByLabelText('Add a section'), { target: { value: 'faq' } });

        expect(screen.getByText('Questions that open and close.')).toBeInTheDocument();
    });

    it('will not add nothing', () => {
        render(<Edit {...props()} />);

        expect(screen.getByRole('button', { name: 'Add to this page' })).toBeDisabled();
    });
});

describe('removing a section', () => {
    it('asks first, and says what will be lost', () => {
        render(<Edit {...props()} />);

        fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[0]);
        fireEvent.click(screen.getByRole('button', { name: 'Remove section' }));

        expect(screen.getByText(/The words in it are deleted/)).toBeInTheDocument();

        // And it points at the gentler option, because hiding is what somebody
        // usually means.
        expect(screen.getByText(/untick/)).toBeInTheDocument();
        expect(routerDelete).not.toHaveBeenCalled();
    });
});
