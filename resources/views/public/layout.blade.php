{{--
    Public site layout — Blade, no Inertia  [D-05, UI §2.1]

    This layout must render usefully with JavaScript disabled. The Emergency
    Maintenance Instructions page (WP-18) is the reason the whole public site is
    Blade: a tenant with a burst pipe on a bad mobile connection has to be able
    to read it. Do not add a JS dependency to this layout.

    Tailwind is the same build the Inertia side uses — one design system, two
    pipelines (tailwind.config.js globs both trees).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $company['name'])</title>
    <meta name="description" content="@yield('meta_description', '')">
    {{-- CSS only. No JS bundle is loaded on the public site at all. --}}
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen flex-col bg-white text-base text-gray-900 antialiased">
    <a href="#main"
       class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-3 focus:outline focus:outline-2 focus:outline-brand-600">
        Skip to content
    </a>

    <header class="border-b border-gray-200">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
            <a href="{{ route('public.home') }}" class="text-lg font-semibold">{{ $company['name'] }}</a>

            <nav aria-label="Main" class="flex flex-wrap items-center gap-x-4 gap-y-1">
                {{-- From the content table (WP-36), with a literal fallback in
                     the composer so a site with no content still has a menu. --}}
                @foreach ($navigation as $item)
                    @php $name = $item['route']; $label = $item['label']; @endphp
                    <a href="{{ route($name) }}"
                       @if (request()->routeIs($name)) aria-current="page" @endif
                       class="inline-flex min-h-touch items-center text-base
                              {{ request()->routeIs($name) ? 'font-semibold text-brand-700' : 'text-gray-700 hover:text-gray-900' }}
                              focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            <a href="{{ route('login') }}"
               class="ml-auto inline-flex min-h-touch items-center rounded-md bg-brand-600 px-4 py-2 text-base font-semibold text-white hover:bg-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                Tenant Login
            </a>
        </div>
    </header>

    <main id="main" class="mx-auto w-full max-w-6xl flex-1 px-4 py-8">
        @yield('content')
    </main>

    <footer class="border-t border-gray-200 bg-gray-50">
        <div class="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:grid-cols-3">
            <div>
                <p class="font-semibold">{{ $company['name'] }}</p>
                @if ($company['address'])
                    <p class="mt-1 text-gray-700">{{ $company['address'] }}</p>
                @endif
                @if ($company['phone'])
                    <p class="mt-1 text-gray-700">
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $company['phone']) }}" class="underline">
                            {{ $company['phone'] }}
                        </a>
                    </p>
                @endif
            </div>

            {{-- Emergency number is given its own block and never buried in a
                 list: this is the one piece of information on the public site
                 that someone may be looking for in a hurry. --}}
            <div>
                <p class="font-semibold text-overdue-fg">Emergency maintenance</p>
                @if ($company['emergency_phone'])
                    <p class="mt-1">
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $company['emergency_phone']) }}"
                           class="text-lg font-semibold underline">
                            {{ $company['emergency_phone'] }}
                        </a>
                    </p>
                @else
                    {{-- [GATE] company.emergency_phone is unset. WP-35 blocks
                         go-live while it is empty. --}}
                    <p class="mt-1 text-gray-700">Number not yet configured.</p>
                @endif
                <p class="mt-1 text-sm text-gray-600">
                    For fire, gas or flooding, call 911 first.
                </p>
            </div>

            <div>
                <p class="font-semibold">Resources</p>
                <ul class="mt-1 space-y-1 text-gray-700">
                    <li>
                        <a href="https://www.dca.ga.gov/" rel="noopener noreferrer" target="_blank" class="underline">
                            Georgia DCA
                        </a>
                    </li>
                    <li><a href="{{ route('public.privacy') }}" class="underline">Privacy</a></li>
                    <li><a href="{{ route('public.terms') }}" class="underline">Terms</a></li>
                </ul>
            </div>
        </div>

        <div class="border-t border-gray-200 px-4 py-4 text-center text-sm text-gray-600">
            &copy; {{ now()->year }} {{ $company['name'] }}
        </div>
    </footer>
</body>
</html>
