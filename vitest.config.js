import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

/**
 * Frontend tests.  [WP-05]
 *
 * The PHP suite cannot reach the rules that live in React — the Credit
 * rendering, the two-decimal guarantee, and later the invariant that no
 * tenant-facing component may receive the Housing Authority portion (I-4).
 * Those need a JS runner.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/**/*.test.jsx'],
        setupFiles: ['./resources/js/test-setup.js'],
    },
});
