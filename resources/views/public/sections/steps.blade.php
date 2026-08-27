{{-- Steps. Numbered because the order carries information — see UI §9 on
     structure that encodes something true rather than decorating. --}}
<section class="hupm-reveal border-t border-gray-200 py-12 sm:py-16">
    @if ($s['heading'] ?? '')
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">{{ $s['heading'] }}</h2>
    @endif

    @if ($s['intro'] ?? '')
        <p class="mt-3 max-w-prose text-lg text-gray-700">{{ $s['intro'] }}</p>
    @endif

    <ol class="mt-8 grid gap-6 sm:grid-cols-2">
        @foreach ($s['items'] ?? [] as $index => $item)
            <li class="hupm-reveal hupm-reveal-{{ min($index + 1, 4) }} flex gap-4">
                <span aria-hidden="true"
                      class="flex h-9 w-9 flex-none items-center justify-center rounded-full bg-brand-50 text-base font-semibold text-brand-700">
                    {{ $index + 1 }}
                </span>
                <div>
                    <p class="text-lg font-semibold text-gray-900">{{ $item['title'] ?? '' }}</p>
                    <p class="mt-1 leading-relaxed text-gray-700">{{ $item['body'] ?? '' }}</p>
                </div>
            </li>
        @endforeach
    </ol>
</section>
