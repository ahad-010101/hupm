<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- No webfont. Breeze shipped a fonts.bunny.net link; it was removed
             deliberately. A third-party stylesheet blocks text rendering on
             every page load, and this application is used on poor mobile
             connections at bad moments. It also sent every tenant's browser to
             an external host for no benefit the system needs. The system font
             stack renders instantly and looks native on each device. --}}

        {{-- Ziggy's @routes was removed deliberately. It serialises EVERY named
             route -- including every admin endpoint and its parameter names --
             into the page source of every response, tenant portal included.
             That hands a resident a complete map of the admin surface for no
             benefit: nothing in this application calls route() from
             JavaScript, because Inertia <Link href> takes a plain path. --}}

        <!-- Scripts -->
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
