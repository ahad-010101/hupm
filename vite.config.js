import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            // Two entry points, one build — see D-05.
            //   app.css  : the Blade public site loads this alone, with no JS bundle,
            //              so Emergency Maintenance Instructions renders with
            //              JavaScript disabled.
            //   app.jsx  : the Inertia portal and admin console. It imports app.css
            //              itself, so the React side still gets one stylesheet.
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
    ],
    // Do not set build.manifest here — laravel-vite-plugin writes the manifest to
    // public/build/manifest.json itself, and overriding it moves the file to
    // public/build/.vite/ where the @vite directive will not find it.
});
