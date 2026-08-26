{{-- Links. The only block that may leave the site. Every outward link opens in
     a new tab with rel="noopener noreferrer", and the catalogue has already
     refused anything that is not http or https. --}}
<section class="border-t border-gray-200 py-12 sm:py-16">
    @if ($s['heading'] ?? '')
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">{{ $s['heading'] }}</h2>
    @endif

    @if ($s['intro'] ?? '')
        <p class="mt-3 max-w-prose text-lg text-gray-700">{{ $s['intro'] }}</p>
    @endif

    <ul class="mt-8 grid gap-5 sm:grid-cols-2">
        @foreach ($s['items'] ?? [] as $item)
            <li class="rounded-xl border border-gray-200 bg-white p-6">
                <p class="text-lg font-semibold">
                    <a href="{{ $item['url'] ?? '#' }}" rel="noopener noreferrer" target="_blank"
                       class="text-gray-900 underline decoration-brand-600 decoration-2 underline-offset-4 hover:text-brand-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        {{ $item['title'] ?? '' }}
                        {{-- Says "opens in a new tab" to a screen reader. A new
                             tab with no warning is disorienting for anyone who
                             cannot see it happen. --}}
                        <span class="sr-only">(opens in a new tab)</span>
                    </a>
                </p>
                <p class="mt-2 leading-relaxed text-gray-700">{{ $item['blurb'] ?? '' }}</p>
            </li>
        @endforeach
    </ul>
</section>
