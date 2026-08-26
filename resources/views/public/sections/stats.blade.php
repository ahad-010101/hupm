{{-- Figures. Large numbers with the sentence that makes them mean something —
     a number on its own is decoration.

     A real description list: the label is the term, the figure is what is being
     said about it. Reversed visually so the number leads, which is the whole
     point of a stats band, without the DOM order lying about which is which.
     An earlier draft carried an sr-only <dt> beside a visible label, and a
     screen reader read every stat twice. --}}
<section class="border-t border-gray-200 py-12 sm:py-16">
    @if ($s['heading'] ?? '')
        <h2 class="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">{{ $s['heading'] }}</h2>
    @endif

    @php
        $items = $s['items'] ?? [];

        // Literals only — an interpolated Tailwind class is never in the built
        // CSS, and there is no Node on the production host to notice.
        $columns = match (count($items)) {
            1 => 'sm:grid-cols-1',
            2 => 'sm:grid-cols-2',
            4 => 'sm:grid-cols-2 lg:grid-cols-4',
            default => 'sm:grid-cols-3',
        };
    @endphp

    <dl class="mt-8 grid gap-6 {{ $columns }}">
        @foreach ($items as $item)
            <div class="flex flex-col-reverse rounded-xl bg-brand-50 p-6">
                <dt class="mt-2">
                    <span class="block text-base font-medium text-gray-900">{{ $item['label'] ?? '' }}</span>
                    @if ($item['note'] ?? '')
                        <span class="mt-1 block text-sm leading-relaxed text-gray-700">{{ $item['note'] }}</span>
                    @endif
                </dt>
                <dd class="text-4xl font-semibold tracking-tight text-brand-700">
                    {{ $item['value'] ?? '' }}
                </dd>
            </div>
        @endforeach
    </dl>
</section>
