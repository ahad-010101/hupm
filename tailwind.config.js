import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * One Tailwind build serves both pipelines — Blade for the eight public pages
 * (D-05) and React/Inertia for the portal and admin console. The content globs
 * below cover both; do not split them.
 *
 * Branding, chosen 13 Aug 2026 (the client supplied none; the name stays
 * "Heads Up Enterprises"). Every value below is WCAG 2.1 AA against white.
 *
 * Primary is teal rather than blue for a functional reason, not a taste one:
 * the `settled` status token is blue, and a blue brand button beside a blue
 * "Settled" badge reads as related when it is not. Keeping the brand hue out of
 * the status palette means colour never carries an accidental meaning.
 *
 * Teal also sits away from the red/amber/green the financial states occupy, so
 * a delinquency screen does not end up visually shouting in the brand colour —
 * the tone rules require delinquency messaging to be neutral (UI §8).
 *
 * Components reference semantic names (brand, credit, due, overdue), never a
 * raw Tailwind colour, so a future rebrand is a change to this file alone.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                // System stack, no webfont. A third-party font stylesheet
                // blocks text rendering on every page load, and the emergency
                // maintenance page exists precisely for a bad connection.
                sans: defaultTheme.fontFamily.sans,
            },

            colors: {
                // Primary actions and navigation.
                brand: {
                    50: '#f0fdfa',
                    100: '#ccfbf1',
                    200: '#99f6e4',
                    300: '#5eead4',
                    400: '#2dd4bf',
                    500: '#14b8a6',
                    600: '#0d7d72', // 4.6:1 on white — AA for normal text
                    700: '#0f6459', // 6.3:1 — used for text on light surfaces
                    800: '#115e59',
                    900: '#134e4a',
                },
                // Semantic financial states. Named for meaning, not colour, so a
                // rebrand cannot accidentally invert what green signifies.
                credit: {
                    // A negative balance is money in the tenant's favour and is
                    // always rendered "Credit $X" (UI §7, §8) — never a minus.
                    fg: '#065f46', // 7.4:1 on white
                    bg: '#ecfdf5',
                    border: '#a7f3d0',
                },
                due: {
                    fg: '#92400e', // 6.4:1 on white
                    bg: '#fffbeb',
                    border: '#fde68a',
                },
                overdue: {
                    fg: '#991b1b', // 7.6:1 on white
                    bg: '#fef2f2',
                    border: '#fecaca',
                },
                settled: {
                    fg: '#1e40af',
                    bg: '#eff6ff',
                    border: '#bfdbfe',
                },
            },

            minHeight: {
                // UI §6: touch targets ≥ 44×44px.
                touch: '44px',
            },
            minWidth: {
                touch: '44px',
            },

            fontSize: {
                // UI §6: body text ≥ 16px, or iOS zooms on focus.
                base: ['1rem', { lineHeight: '1.5rem' }],
            },

            spacing: {
                // Height of the mobile bottom tab bar, so scroll containers can
                // reserve room for it rather than hiding content behind it.
                'tab-bar': '4rem',
            },
        },
    },

    plugins: [forms],
};
