import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

/*
 * Reporting a repair — errors on a panel you cannot see.  [WP-19, UI §7, §8]
 *
 * The form is three panels and submits from the last one. A rejected field may
 * belong to an earlier panel, and its message renders inside markup that is no
 * longer on screen — so the tenant pressed Send, nothing happened, and nothing
 * explained why. The button looked broken.
 *
 * The PHP suite cannot see this: it posts straight to the endpoint and gets a
 * perfectly good 422 back. Only the browser knows the message was rendered
 * somewhere nobody was looking.
 */

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }) => children,
    useForm: () => ({
        data: {
            emergency_acknowledged: true,
            category: 'plumbing',
            description: '',
            date_began: '',
            permission_to_enter: '',
            preferred_contact: 'email',
            contact_phone: '',
            pets_present: false,
            best_access_time: '',
            is_emergency: false,
        },
        setData: vi.fn(),
        post: vi.fn(),
        transform: vi.fn(),
        processing: false,
        // What the server sends back when the description is too short — a
        // field on the *second* panel, while the tenant is on the third.
        errors: { description: 'Please tell us what is wrong.' },
    }),
}));

vi.mock('@/Layouts/PortalLayout', () => ({ default: ({ children }) => children }));

const { default: New, STEP_FIELDS, firstStepWithError } = await import('./New');

describe('firstStepWithError', () => {
    it('finds the panel a rejected field belongs to', () => {
        expect(firstStepWithError({ emergency_acknowledged: 'x' })).toBe(0);
        expect(firstStepWithError({ description: 'x' })).toBe(1);
        expect(firstStepWithError({ contact_phone: 'x' })).toBe(2);
    });

    it('returns the earliest panel when more than one is wrong', () => {
        expect(firstStepWithError({ contact_phone: 'x', description: 'y' })).toBe(1);
    });

    it('reads photos.0 as photos, because that is how the server names it', () => {
        expect(firstStepWithError({ 'photos.0': 'Too big.' })).toBe(2);
    });

    it('sends an unrecognised field to the last panel rather than swallowing it', () => {
        // A field nobody listed still has to be seen. Silently returning null
        // would put us back to a Send button that does nothing.
        expect(firstStepWithError({ something_new: 'x' })).toBe(STEP_FIELDS.length - 1);
    });

    it('is null when nothing is wrong', () => {
        expect(firstStepWithError({})).toBeNull();
        expect(firstStepWithError(undefined)).toBeNull();
    });
});

describe('the form', () => {
    it('marks the panel that needs attention, in words as well as colour', () => {
        render(<New hasLease categories={{ plumbing: 'Plumbing' }} unit="unit 1" />);

        const steps = screen.getByLabelText('Progress');

        // UI §9: never colour alone.
        expect(steps.textContent).toContain('What is wrong!');
        expect(steps.textContent).not.toContain('Emergency!');
    });
});
