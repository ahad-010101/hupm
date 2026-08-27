{{-- Questions. Native <details>, so they open and close with JavaScript
     switched off — which on the public site is every time (D-05). --}}
<section class="hupm-reveal border-t border-gray-200 py-12 sm:py-16">
    @if ($s['heading'] ?? '')
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">{{ $s['heading'] }}</h2>
    @endif

    <div class="mt-6 max-w-3xl divide-y divide-gray-200 border-y border-gray-200">
        @foreach ($s['items'] ?? [] as $item)
            <details class="group py-4">
                <summary class="flex min-h-touch cursor-pointer items-center justify-between gap-4 text-lg font-medium text-gray-900 marker:content-none [&::-webkit-details-marker]:hidden focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                    {{ $item['question'] ?? '' }}
                    {{-- Rotates on open. Marked aria-hidden: the disclosure
                         state is already announced by <details> itself, and a
                         screen reader does not need the glyph too. --}}
                    <span aria-hidden="true" class="hupm-marker text-2xl leading-none text-brand-700">+</span>
                </summary>
                <p class="mt-3 max-w-prose leading-relaxed text-gray-700">{{ $item['answer'] ?? '' }}</p>
            </details>
        @endforeach
    </div>
</section>
