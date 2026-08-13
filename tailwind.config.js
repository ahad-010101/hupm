import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * One Tailwind build serves both pipelines — Blade for the eight public pages
 * (D-05) and React/Inertia for the portal and admin console. The content globs
 * below cover both; do not split them.
 *
 * [GATE] Client branding has not been supplied. The palette below is a neutral,
 * WCAG 2.1 AA-checked placeholder. When branding arrives it is a change to this
 * file alone — every component references semantic names (brand, credit, due,
 * overdue), never a raw Tailwind colour, so nothing else has to move.
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
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                // Primary actions and navigation.
                brand: {
                    50: '#eef4ff',
                    100: '#d9e6ff',
                    200: '#bcd3ff',
                    300: '#8eb6ff',
                    400: '#598eff',
                    500: '#3366f2',
                    600: '#1f47d6', // 5.9:1 on white — AA for normal text
                    700: '#1a3aae',
                    800: '#1b338a',
                    900: '#1c306e',
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
