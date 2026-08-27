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
    server: {
        /*
         | Bind the dev server to IPv4 loopback explicitly.
         |
         | Left to itself Vite binds to `::1` and writes that into `public/hot`,
         | so every @vite tag points the browser at `http://[::1]:5173` while
         | the application is being served from `http://127.0.0.1:8000`. The two
         | loopbacks are different addresses, and 127.0.0.1:5173 is then not
         | listening at all.
         |
         | It breaks the PUBLIC site first and worst. Those eight Blade pages
         | load `resources/css/app.css` as its own Vite entry with no JS bundle
         | (D-05), so the stylesheet is a real cross-origin <link> — and if it
         | does not load, the page renders as unstyled HTML. The Inertia side
         | survives longer because app.jsx imports the CSS and injects it.
         |
         | Pinning the host makes `hot` say `http://127.0.0.1:5173`, matching
         | APP_URL's host, which is also the origin the CSP admits.
        */
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
    },
    // Do not set build.manifest here — laravel-vite-plugin writes the manifest to
    // public/build/manifest.json itself, and overriding it moves the file to
    // public/build/.vite/ where the @vite directive will not find it.
});
