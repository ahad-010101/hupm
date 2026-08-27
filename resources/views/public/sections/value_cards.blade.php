{{-- Cards. Two to four, equal weight, no ordering implied. --}}
<section class="hupm-reveal py-12 sm:py-16">
    @if ($s['heading'] ?? '')
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">{{ $s['heading'] }}</h2>
    @endif

    @if ($s['intro'] ?? '')
        <p class="mt-3 max-w-prose text-lg text-gray-700">{{ $s['intro'] }}</p>
    @endif

    @php
        $items = $s['items'] ?? [];

        // A full literal per case, never "grid-cols-{$n}". Tailwind scans this
        // file for strings; a class assembled at render time is never in the
        // built CSS, and there is no Node on the production host to notice.
        $columns = match (count($items)) {
            1 => 'sm:grid-cols-1',
            2 => 'sm:grid-cols-2',
            4 => 'sm:grid-cols-2 lg:grid-cols-4',
            default => 'sm:grid-cols-2 lg:grid-cols-3',
        };
    @endphp

    @if ($items)
        <div class="mt-8 grid gap-5 {{ $columns }}">
            @foreach ($items as $index => $item)
                <div class="hupm-reveal hupm-reveal-{{ min($index + 1, 4) }} hupm-lift rounded-xl border border-gray-200 bg-white p-6">
                    <p class="text-lg font-semibold text-gray-900">{{ $item['title'] ?? '' }}</p>
                    <p class="mt-2 leading-relaxed text-gray-700">{{ $item['body'] ?? '' }}</p>
                </div>
            @endforeach
        </div>
    @endif
</section>
