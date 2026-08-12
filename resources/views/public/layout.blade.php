{{--
    Public site layout — Blade, no Inertia  [DEVIATION D-05]

    This layout must render usefully with JavaScript disabled. The Emergency
    Maintenance Instructions page (WP-18) is the reason the whole public site
    is Blade: a tenant with a burst pipe on a bad mobile connection has to be
    able to read it. Do not add a JS dependency to this layout.

    Tailwind is the same build the Inertia side uses (tailwind.config.js globs
    both resources/views and resources/js) — one design system, two pipelines.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('meta_description', '')">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-white text-gray-900 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:p-3 focus:bg-white">
        Skip to content
    </a>

    <header class="border-b border-gray-200">
        <nav class="mx-auto flex max-w-5xl items-center justify-between p-4" aria-label="Main">
            <a href="{{ route('public.home') }}" class="font-semibold">{{ config('app.name') }}</a>
            {{-- WP-18 adds the full public navigation here. --}}
            <a href="{{ route('login') }}" class="text-sm underline">Resident Login</a>
        </nav>
    </header>

    <main id="main" class="mx-auto max-w-5xl p-4">
        @yield('content')
    </main>

    <footer class="mt-12 border-t border-gray-200">
        <div class="mx-auto max-w-5xl p-4 text-sm text-gray-600">
            &copy; {{ now()->year }} {{ config('app.name') }}
        </div>
    </footer>
</body>
</html>
